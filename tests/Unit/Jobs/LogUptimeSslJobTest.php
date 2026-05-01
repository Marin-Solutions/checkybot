<?php

use App\Enums\RunSource;
use App\Jobs\LogUptimeSslJob;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\SslCertificateService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
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

test('on-demand uptime jobs use a separate unique key', function () {
    $website = Website::factory()->create();

    $scheduledJob = new LogUptimeSslJob($website);
    $onDemandJob = new LogUptimeSslJob($website, onDemand: true);

    expect($onDemandJob->uniqueId())
        ->toBe("website-uptime-ssl:{$website->id}:".RunSource::OnDemand->value)
        ->not->toBe($scheduledJob->uniqueId());
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
    expect($website->last_heartbeat_at)->not->toBeNull();
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

test('on-demand runs do not notify and leave the live status fields untouched', function () {
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
        ->and($website->current_status)->toBe('healthy')
        ->and($website->last_heartbeat_at)->toBeNull()
        ->and($website->status_summary)->toBeNull();

    Mail::assertNothingSent();
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

test('job leaves uptime-only status based on http when ssl evidence is unavailable', function () {
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
            ->andThrow(new RuntimeException('No certificate'));
    });

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

test('on-demand job records combined ssl risk without changing live status', function () {
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

    $job = new LogUptimeSslJob($website, onDemand: true);
    $job->handle(app(SslCertificateService::class));

    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();
    $website->refresh();

    expect($history?->status)->toBe('warning')
        ->and($history?->summary)->toBe('SSL certificate expires in 6 day(s).')
        ->and($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('Scheduler says the site is healthy.');

    Mail::assertNothingSent();
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
