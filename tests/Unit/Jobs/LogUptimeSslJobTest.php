<?php

use App\Enums\RunSource;
use App\Jobs\LogUptimeSslJob;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\SslCertificateService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

test('scheduled uptime jobs are unique per website', function () {
    $website = Website::factory()->create();

    $job = new LogUptimeSslJob($website);

    expect($job)
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("website-uptime-ssl:{$website->id}:scheduled");
});

test('on-demand uptime jobs use a per-dispatch unique key', function () {
    $website = Website::factory()->create();

    $scheduledJob = new LogUptimeSslJob($website);
    $firstOnDemandJob = new LogUptimeSslJob($website, onDemand: true, diagnosticRunId: 'first-run');
    $secondOnDemandJob = new LogUptimeSslJob($website, onDemand: true, diagnosticRunId: 'second-run');

    expect($firstOnDemandJob->uniqueId())
        ->toBe("website-uptime-ssl:{$website->id}:".RunSource::OnDemand->value.':first-run')
        ->not->toBe($scheduledJob->uniqueId())
        ->and($secondOnDemandJob->uniqueId())
        ->toBe("website-uptime-ssl:{$website->id}:".RunSource::OnDemand->value.':second-run')
        ->not->toBe($firstOnDemandJob->uniqueId());
});

test('uptime job unique locks extend beyond the website interval', function () {
    $website = Website::factory()->create([
        'uptime_interval' => 1,
    ]);

    $job = new LogUptimeSslJob($website);

    expect($job->uniqueFor())->toBe(3660);
});

test('on-demand uptime jobs use a short diagnostic unique lock', function () {
    $website = Website::factory()->create([
        'uptime_interval' => 60,
    ]);

    $job = new LogUptimeSslJob($website, onDemand: true);

    expect($job->uniqueFor())->toBe(60);
});

test('job skips diagnostics and clears queued state when batch is cancelled', function () {
    Http::preventStrayRequests();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'diagnostic_queued_at' => now(),
    ]);

    [$job] = (new LogUptimeSslJob($website, onDemand: true))
        ->withFakeBatch(cancelledAt: now()->toImmutable());

    $job->handle(app(SslCertificateService::class));

    expect($website->fresh()->diagnostic_queued_at)->toBeNull()
        ->and($website->logHistory()->count())->toBe(0);
});

test('on-demand job skips websites disabled after dispatch and clears queued state', function () {
    Http::preventStrayRequests();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('extractHost');
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'diagnostic_queued_at' => now(),
    ]);

    $job = new LogUptimeSslJob($website, onDemand: true);

    $website->update([
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    $job->handle(app(SslCertificateService::class));

    expect($website->fresh()->diagnostic_queued_at)->toBeNull()
        ->and($website->logHistory()->count())->toBe(0);
});

test('job skips live update when website is deleted after the outbound request', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    Http::fake(function () use ($website) {
        $website->delete();

        return Http::response('', 200);
    });

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    expect(Website::query()->find($website->id))->toBeNull()
        ->and(WebsiteLogHistory::query()->where('website_id', $website->id)->count())->toBe(0);
});

test('job skips cleanly when website is deleted before heartbeat history is inserted', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    WebsiteLogHistory::creating(function () use ($website): void {
        $website->forceDelete();
    });

    try {
        $job = new LogUptimeSslJob($website);

        expect(fn () => $job->handle(app(SslCertificateService::class)))->not->toThrow(\Throwable::class);
    } finally {
        WebsiteLogHistory::flushEventListeners();
    }

    expect(Website::withTrashed()->find($website->id))->toBeNull()
        ->and(WebsiteLogHistory::query()->where('website_id', $website->id)->count())->toBe(0);
});

test('job creates log history for successful check', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    assertDatabaseHas('website_log_history', [
        'website_id' => $website->id,
        'http_status_code' => 200,
    ]);
});

test('scheduled job writes heartbeat history before the locked live status transaction', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'current_status' => 'danger',
    ]);

    $operations = [];

    DB::listen(function (QueryExecuted $query) use (&$operations): void {
        $operations[] = strtolower($query->sql);
    });

    Event::listen(function (TransactionBeginning $event) use (&$operations): void {
        $operations[] = 'begin transaction';
    });

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $historyInsertIndex = collect($operations)->search(
        fn (string $query): bool => str_contains($query, 'insert into')
            && str_contains($query, 'website_log_history')
    );
    $transactionBeginIndex = collect($operations)->search(
        fn (string $query): bool => str_contains($query, 'begin transaction')
    );
    $liveStatusUpdateIndex = collect($operations)->search(
        fn (string $query): bool => str_contains($query, 'update')
            && str_contains($query, 'websites')
            && str_contains($query, 'current_status')
    );

    expect($historyInsertIndex)->not->toBeFalse()
        ->and($transactionBeginIndex)->not->toBeFalse()
        ->and($liveStatusUpdateIndex)->not->toBeFalse()
        ->and($historyInsertIndex)->toBeLessThan($transactionBeginIndex)
        ->and($transactionBeginIndex)->toBeLessThan($liveStatusUpdateIndex);
});

test('job records response time', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $log = WebsiteLogHistory::where('website_id', $website->id)->first();

    expect($log)->not->toBeNull();
    expect($log->speed)->toBeInt();
    expect($log->speed)->toBeGreaterThanOrEqual(0);
});

test('job handles failed requests', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    assertDatabaseHas('website_log_history', [
        'website_id' => $website->id,
        'http_status_code' => 500,
    ]);
});

test('job records structured transport error evidence for connection exceptions', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 6: Could not resolve host: missing.example');
    });

    $website = Website::factory()->create([
        'url' => 'https://missing.example',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $log = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($log?->http_status_code)->toBe(0)
        ->and($log?->status)->toBe('danger')
        ->and($log?->summary)->toBe('Website heartbeat failed before an HTTP response: DNS lookup failed.')
        ->and($log?->transport_error_type)->toBe('dns')
        ->and($log?->transport_error_message)->toContain('Could not resolve host')
        ->and($log?->transport_error_code)->toBe(6)
        ->and($website->status_summary)->toBe('Website heartbeat failed before an HTTP response: DNS lookup failed.');
});

test('job classifies request timeouts separately from other transport failures', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
    });

    $website = Website::factory()->create([
        'url' => 'https://slow.example',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $log = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($log?->transport_error_type)->toBe('timeout')
        ->and($log?->transport_error_code)->toBe(28)
        ->and($log?->summary)->toBe('Website heartbeat failed before an HTTP response: the request timed out.');
});

test('job records danger status history and sends notifications for failed package-managed heartbeats', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();
    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($website->current_status)->toBe('danger');
    expect($history?->status)->toBe('danger');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class);
});

test('job sends recovery notifications when a package-managed website returns to healthy', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'danger',
        'stale_at' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->current_status)->toBe('healthy')
        ->and($website->stale_at)->toBeNull();

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy'
            && $mail->summary === 'Website heartbeat succeeded with HTTP status 200.';
    });
});

test('job sends recovery notifications when a warning website returns to healthy', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'warning',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy';
    });
});

test('on-demand runs update live status and notify on transitions', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
        'status_summary' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website, onDemand: true);
    $job->handle(app(SslCertificateService::class));

    $log = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($log?->status)->toBe('danger')
        ->and($log?->run_source)->toBe(RunSource::OnDemand)
        ->and($log?->is_on_demand)->toBeTrue()
        ->and($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('Website heartbeat failed with HTTP status 500.');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class);
});

test('job sends notifications for failed manual website heartbeats', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->current_status)->toBe('danger');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, 1);
});

test('job rolls expired ssl certificate into live website status', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-04-23 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->http_status_code)->toBe(200)
        ->and($history?->status)->toBe('danger')
        ->and($history?->summary)->toBe('SSL certificate expired 1 day(s) ago.')
        ->and($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('SSL certificate expired 1 day(s) ago.');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'heartbeat'
            && $mail->status === 'danger'
            && $mail->summary === 'SSL certificate expired 1 day(s) ago.';
    });
});

test('scheduled job syncs ssl expiry date to website when certificate is read', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-06-01 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => Carbon::parse('2026-05-01'),
        'ssl_expiry_reminder_sent_at' => now()->subHour(),
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->ssl_expiry_date?->isSameDay('2026-06-01'))->toBeTrue()
        ->and(Carbon::parse($website->ssl_expiry_date)->isSameDay('2026-06-01'))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at)->toBeNull();
});

test('scheduled job clears stale website ssl expiry date when certificate cannot be read', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andThrow(new RuntimeException('certificate unavailable'));
    });

    $reminderSentAt = now()->subHour();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => Carbon::parse('2026-05-01'),
        'ssl_expiry_reminder_sent_at' => $reminderSentAt,
        'current_status' => 'healthy',
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->ssl_expiry_date)->toBeNull()
        ->and($history?->status)->toBe('danger')
        ->and($history?->summary)->toBe('SSL certificate check failed before an expiry date could be read.')
        ->and($website->ssl_expiry_date)->toBeNull()
        ->and($website->ssl_expiry_reminder_sent_at?->equalTo($reminderSentAt))->toBeTrue()
        ->and($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('SSL certificate check failed before an expiry date could be read.');
});

test('scheduled job skips latest ssl history lookup when current ssl expiry is unavailable', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andThrow(new RuntimeException('certificate unavailable'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => now()->subHour(),
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'ssl_expiry_date' => Carbon::parse('2026-05-01 09:00:00'),
        'created_at' => now()->subMinutes(5),
    ]);

    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $latestSslLookupCount = collect($queries)
        ->filter(fn (string $query): bool => str_contains($query, 'select')
            && str_contains($query, 'ssl_expiry_date')
            && str_contains($query, 'website_log_history')
            && str_contains($query, 'order by'))
        ->count();

    expect($latestSslLookupCount)->toBe(0);
});

test('scheduled job preserves ssl reminder throttle when unknown expiry recovers to same date', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-05-01 09:00:00'));
    });

    $reminderSentAt = now()->subHour();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => $reminderSentAt,
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'ssl_expiry_date' => Carbon::parse('2026-05-01 09:00:00'),
        'is_on_demand' => false,
        'created_at' => now()->subMinutes(5),
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay('2026-05-01'))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at?->equalTo($reminderSentAt))->toBeTrue();
});

test('scheduled job bounds previous ssl expiry lookup to history before the current row', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-05-01 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => now()->subHour(),
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'ssl_expiry_date' => Carbon::parse('2026-05-01 09:00:00'),
        'created_at' => now()->subMinutes(5),
    ]);

    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $latestSslLookup = collect($queries)->first(
        fn (string $query): bool => str_contains($query, 'select')
            && str_contains($query, 'ssl_expiry_date')
            && str_contains($query, 'website_log_history')
            && str_contains($query, 'order by')
    );

    expect($latestSslLookup)->toBeString()
        ->and($latestSslLookup)->toContain('"id" < ?')
        ->and($latestSslLookup)->not->toContain('"id" != ?');
});

test('scheduled job resets ssl reminder using the current website expiry instead of stale queued state', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-05-01 09:00:00'));
    });

    $reminderSentAt = now()->subHour();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => $reminderSentAt,
    ]);
    $queuedWebsite = Website::findOrFail($website->id);

    $queuedWebsite->ssl_expiry_date = Carbon::parse('2026-05-01 09:00:00');

    $job = new LogUptimeSslJob($queuedWebsite);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay('2026-05-01'))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at)->toBeNull();
});

test('scheduled job does not overwrite a fresher ssl expiry snapshot when certificate cannot be read', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andThrow(new RuntimeException('certificate unavailable'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => Carbon::parse('2026-05-01'),
    ]);
    $queuedWebsite = Website::findOrFail($website->id);

    Carbon::setTestNow('2026-04-24 12:00:10');
    $website->forceFill([
        'ssl_expiry_date' => Carbon::parse('2026-06-01'),
    ])->save();

    $job = new LogUptimeSslJob($queuedWebsite);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay('2026-06-01'))->toBeTrue();
});

test('scheduled job does not overwrite a fresher ssl reminder timestamp when expiry is unchanged', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-05-01 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => Carbon::parse('2026-05-01'),
        'ssl_expiry_reminder_sent_at' => null,
    ]);
    $queuedWebsite = Website::findOrFail($website->id);

    Carbon::setTestNow('2026-04-24 12:00:10');
    $reminderSentAt = now();
    $website->forceFill([
        'ssl_expiry_reminder_sent_at' => $reminderSentAt,
    ])->save();

    $job = new LogUptimeSslJob($queuedWebsite);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay('2026-05-01'))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at?->equalTo($reminderSentAt))->toBeTrue();
});

test('on-demand job syncs ssl expiry date to website', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-06-01 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'ssl_expiry_date' => Carbon::parse('2026-05-01'),
        'ssl_expiry_reminder_sent_at' => now()->subHour(),
    ]);

    $job = new LogUptimeSslJob($website, onDemand: true);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->ssl_expiry_date?->isSameDay('2026-06-01'))->toBeTrue()
        ->and(Carbon::parse($website->ssl_expiry_date)->isSameDay('2026-06-01'))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at)->toBeNull();
});

test('job includes ssl context when http and ssl are both dangerous', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-04-23 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'current_status' => 'healthy',
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->status)->toBe('danger')
        ->and($history?->summary)->toBe('Website heartbeat failed with HTTP status 500. SSL certificate expired 1 day(s) ago.')
        ->and($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('Website heartbeat failed with HTTP status 500. SSL certificate expired 1 day(s) ago.');
});

test('job keeps http summary when ssl expiry is unavailable and http is dangerous', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andThrow(new RuntimeException('No certificate'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'current_status' => 'healthy',
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->status)->toBe('danger')
        ->and($history?->summary)->toBe('Website heartbeat failed with HTTP status 500.')
        ->and($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('Website heartbeat failed with HTTP status 500.');
});

test('job leaves uptime-only status based on http when ssl check is disabled', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->ssl_expiry_date)->toBeNull()
        ->and($history?->status)->toBe('healthy')
        ->and($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('Website heartbeat succeeded with HTTP status 200.');
});

test('on-demand job records combined ssl risk and updates live status', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-04-30 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'ssl_check' => true,
        'current_status' => 'healthy',
        'status_summary' => 'Scheduler says the site is healthy.',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website, onDemand: true);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->status)->toBe('warning')
        ->and($history?->summary)->toBe('SSL certificate expires in 6 day(s).')
        ->and($website->current_status)->toBe('warning')
        ->and($website->status_summary)->toBe('SSL certificate expires in 6 day(s).');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class);
});

test('job sends recovery notifications when a manual website returns to healthy', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'danger',
        'stale_at' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->current_status)->toBe('healthy')
        ->and($website->stale_at)->toBeNull();

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy'
            && $mail->summary === 'Website heartbeat succeeded with HTTP status 200.';
    });
});

test('job does not notify when a manual website remains in the same failing status', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'danger',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->current_status)->toBe('danger');

    Mail::assertNothingSent();
});

test('job tolerates pre-deploy payloads where onDemand was never initialized', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'danger',
        'stale_at' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    /**
     * Simulate a job payload serialized before onDemand existed:
     * the typed property is left uninitialized after unserialization. The handler
     * must not fatally error when reading it, and must fall back to the pre-deploy
     * behaviour (live status fields written, notifications fired) so in-flight
     * scheduled heartbeats keep working through the deploy.
     */
    $reflection = new ReflectionClass(LogUptimeSslJob::class);
    $job = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('website')->setValue($job, $website);

    expect(fn () => $job->handle(app(SslCertificateService::class)))->not->toThrow(\Throwable::class);

    $website->refresh();

    expect($website->current_status)->toBe('healthy');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class);
});

test('job skips websites with uptime check disabled', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => false,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    assertDatabaseMissing('website_log_history', [
        'website_id' => $website->id,
    ]);
});

test('on-demand job records ssl-only evidence when uptime check is disabled', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');
    Http::preventStrayRequests();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(Carbon::parse('2026-05-08 09:00:00'));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => false,
        'ssl_check' => true,
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subHour(),
        'status_summary' => 'Scheduler-owned status.',
    ]);

    $job = new LogUptimeSslJob($website, onDemand: true);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->status)->toBe('warning')
        ->and($history?->summary)->toBe('SSL certificate expires in 14 day(s).')
        ->and($history?->ssl_expiry_date?->isSameDay('2026-05-08'))->toBeTrue()
        ->and($history?->http_status_code)->toBeNull()
        ->and($history?->speed)->toBeNull()
        ->and($history?->run_source)->toBe(RunSource::OnDemand)
        ->and($history?->is_on_demand)->toBeTrue()
        ->and($website->current_status)->toBe('warning')
        ->and($website->status_summary)->toBe('SSL certificate expires in 14 day(s).');
});

test('job resolves the host before looking up the ssl certificate', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com/health?foo=bar')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com/health?foo=bar')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(now()->addDays(30));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com/health?foo=bar',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $log = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($log?->ssl_expiry_date)->not->toBeNull();
    expect(Carbon::parse($log?->ssl_expiry_date)->isFuture())->toBeTrue();
});

test('job logs a warning and continues when ssl host cannot be determined', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Log::spy();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('bad host/path')
            ->andReturn(null);

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('bad host/path')
            ->andReturn(443);

        $mock->shouldNotReceive('getExpirationDateForHost');
    });

    $website = Website::factory()->create([
        'url' => 'bad host/path',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Could not determine SSL host for bad host/path');

    $log = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($log)->not->toBeNull();
    expect($log?->ssl_expiry_date)->toBeNull();
    expect($log?->http_status_code)->toBe(200);
});

test('job preserves custom tls ports during ssl certificate lookup', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('example.com:8443/health')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('example.com:8443/health')
            ->andReturn(8443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 8443)
            ->andReturn(now()->addDays(30));
    });

    $website = Website::factory()->create([
        'url' => 'example.com:8443/health',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle(app(SslCertificateService::class));

    $log = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($log?->ssl_expiry_date)->not->toBeNull();
    expect(Carbon::parse($log?->ssl_expiry_date)->isFuture())->toBeTrue();
});

afterEach(function () {
    Carbon::setTestNow();
});
