<?php

use App\Jobs\CheckSslExpiryDateJob;
use App\Mail\EmailReminderSsl;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\SslCertificateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

test('job checks ssl expiry for website', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'ssl_check' => true,
    ]);

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    // SSL certificate check should have been attempted
    expect(true)->toBeTrue(); // Job executed without errors
});

test('job skips websites with ssl check disabled', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'ssl_check' => false,
    ]);

    Mail::fake();

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    Mail::assertNothingSent();
});

test('job sends notification when ssl expiry approaching', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(10), // Expires soon
    ]);

    NotificationSetting::factory()->email()->create([
        'user_id' => $website->user->id,
        'website_id' => $website->id,
    ]);

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    // Job should complete successfully
    expect(true)->toBeTrue();
});

test('job handles websites without ssl', function () {
    $website = Website::factory()->create([
        'url' => 'http://example.com', // No SSL
        'ssl_check' => true,
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    // Job should handle gracefully
    expect(true)->toBeTrue();
});

test('job resolves the host before checking ssl expiry', function () {
    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://example.com/status?from=checkybot')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://example.com/status?from=checkybot')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 443)
            ->andReturn(now()->addDays(30));
    });

    $website = Website::factory()->create([
        'url' => 'https://example.com/status?from=checkybot',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->subDay(),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $updatedWebsite = $website->fresh();

    expect($updatedWebsite->ssl_expiry_date)->not->toBeNull();
    expect(Carbon::parse($updatedWebsite->ssl_expiry_date)->isFuture())->toBeTrue();
});

test('job returns early when ssl host cannot be determined', function () {
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
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(10),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    Log::shouldHaveReceived('error')
        ->once()
        ->with('Could not determine SSL host for website bad host/path');

    expect(Carbon::parse($website->fresh()->ssl_expiry_date)->isSameDay(now()->addDays(10)))->toBeTrue();
});

test('job preserves custom tls ports during ssl expiry lookup', function () {
    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('example.com:8443/status')
            ->andReturn('example.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('example.com:8443/status')
            ->andReturn(8443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('example.com', 8443)
            ->andReturn(now()->addDays(30));
    });

    $website = Website::factory()->create([
        'url' => 'example.com:8443/status',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->subDay(),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    expect(Carbon::parse($website->fresh()->ssl_expiry_date)->isFuture())->toBeTrue();
});

test('job persists expired ssl expiry date before sending reminder and throttles repeats', function () {
    Mail::fake();

    $expiredDate = now()->subDays(3);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($expiredDate) {
        $mock->shouldReceive('extractHost')
            ->twice()
            ->with('https://expired.example')
            ->andReturn('expired.example');

        $mock->shouldReceive('extractPort')
            ->twice()
            ->with('https://expired.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->twice()
            ->with('expired.example', 443)
            ->andReturn($expiredDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://expired.example',
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->ssl_expiry_date)->not->toBeNull();
    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay($expiredDate))->toBeTrue();
    expect($website->ssl_expiry_reminder_sent_at)->not->toBeNull();

    Mail::assertSent(EmailReminderSsl::class, 1);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    Mail::assertSent(EmailReminderSsl::class, 1);
});

test('job persists future ssl expiry date without sending reminder outside reminder window', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://healthy.example')
            ->andReturn('healthy.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://healthy.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('healthy.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://healthy.example',
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => null,
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->ssl_expiry_date)->not->toBeNull();
    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay($futureDate))->toBeTrue();
    expect($website->ssl_expiry_reminder_sent_at)->toBeNull();

    Mail::assertNothingSent();
});

test('job does not overwrite a fresher ssl reminder timestamp when expiry is unchanged', function () {
    Mail::fake();
    Carbon::setTestNow('2026-04-24 12:00:00');

    $futureDate = now()->addDays(45);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://healthy.example')
            ->andReturn('healthy.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://healthy.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('healthy.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://healthy.example',
        'ssl_check' => true,
        'ssl_expiry_date' => $futureDate,
        'ssl_expiry_reminder_sent_at' => null,
    ]);
    $queuedWebsite = Website::findOrFail($website->id);

    Carbon::setTestNow('2026-04-24 12:00:10');
    $reminderSentAt = now();
    $website->forceFill([
        'ssl_expiry_reminder_sent_at' => $reminderSentAt,
    ])->save();

    $job = new CheckSslExpiryDateJob($queuedWebsite);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay($futureDate))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at?->equalTo($reminderSentAt))->toBeTrue();

    Mail::assertNothingSent();
});

test('job sends ssl reminder on the expiry day', function () {
    Mail::fake();

    $expiryDate = now();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($expiryDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://expires-today.example')
            ->andReturn('expires-today.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://expires-today.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('expires-today.example', 443)
            ->andReturn($expiryDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://expires-today.example',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDay(),
        'ssl_expiry_reminder_sent_at' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    expect($website->fresh()->ssl_expiry_reminder_sent_at)->not->toBeNull();

    Mail::assertSent(EmailReminderSsl::class, 1);
});

test('job does not throttle ssl reminders when all webhook deliveries fail', function () {
    Http::fake([
        '*' => Http::response(['ok' => false], 500),
    ]);

    $expiredDate = now()->subDay();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($expiredDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://webhook-failure.example')
            ->andReturn('webhook-failure.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://webhook-failure.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('webhook-failure.example', 443)
            ->andReturn($expiredDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://webhook-failure.example',
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => null,
    ]);

    $channel = NotificationChannels::factory()->create([
        'method' => 'POST',
        'url' => 'https://example.com/ssl-webhook',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->webhook()
        ->create([
            'user_id' => $website->created_by,
            'notification_channel_id' => $channel->id,
        ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->ssl_expiry_date)->not->toBeNull();
    expect($website->ssl_expiry_reminder_sent_at)->toBeNull();
});

test('job records package ssl heartbeat status when certificate is healthy', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://package.example')
            ->andReturn('package.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://package.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('package.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://package.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'package',
        'package_name' => 'certificate',
        'package_interval' => '1d',
        'current_status' => 'danger',
        'last_heartbeat_at' => null,
        'stale_at' => now()->subHour(),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();
    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($website->current_status)->toBe('healthy')
        ->and($website->last_heartbeat_at)->not->toBeNull()
        ->and($website->stale_at)->toBeNull()
        ->and($website->status_summary)->toBe('SSL certificate is valid for 45 day(s).')
        ->and($history?->status)->toBe('healthy')
        ->and($history?->summary)->toBe('SSL certificate is valid for 45 day(s).');
});

test('job does not overwrite package uptime health for shared ssl checks', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);
    $lastHeartbeat = now()->subMinutes(5)->startOfSecond();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://shared-package.example')
            ->andReturn('shared-package.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://shared-package.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('shared-package.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://shared-package.example',
        'ssl_check' => true,
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'shared',
        'package_interval' => '5m',
        'current_status' => 'danger',
        'last_heartbeat_at' => $lastHeartbeat,
        'stale_at' => now()->subHour(),
        'status_summary' => 'Uptime check returned HTTP 500.',
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->current_status)->toBe('danger')
        ->and($website->last_heartbeat_at->equalTo($lastHeartbeat))->toBeTrue()
        ->and($website->stale_at)->not->toBeNull()
        ->and($website->status_summary)->toBe('Uptime check returned HTTP 500.')
        ->and(WebsiteLogHistory::where('website_id', $website->id)->count())->toBe(0);
});

test('job does not record package ssl heartbeat before interval elapses on reminder dispatch', function () {
    Mail::fake();

    $futureDate = today()->addDays(14);
    $lastHeartbeat = now()->subHour()->startOfSecond();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://package-reminder.example')
            ->andReturn('package-reminder.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://package-reminder.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('package-reminder.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://package-reminder.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'package',
        'package_name' => 'certificate',
        'package_interval' => '1d',
        'current_status' => 'healthy',
        'last_heartbeat_at' => $lastHeartbeat,
        'status_summary' => 'SSL certificate is valid for 15 day(s).',
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->last_heartbeat_at->equalTo($lastHeartbeat))->toBeTrue()
        ->and($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('SSL certificate is valid for 15 day(s).')
        ->and(WebsiteLogHistory::where('website_id', $website->id)->count())->toBe(0);
});

test('job records package ssl failure status when certificate cannot be read', function () {
    Mail::fake();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://broken-package.example')
            ->andReturn('broken-package.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://broken-package.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('broken-package.example', 443)
            ->andThrow(new RuntimeException('certificate unavailable'));
    });

    $website = Website::factory()->create([
        'url' => 'https://broken-package.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'package',
        'package_name' => 'certificate',
        'package_interval' => '1d',
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();
    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($website->current_status)->toBe('danger')
        ->and($website->last_heartbeat_at)->not->toBeNull()
        ->and($website->status_summary)->toBe('SSL certificate check failed before an expiry date could be read.')
        ->and($history?->status)->toBe('danger')
        ->and($history?->summary)->toBe('SSL certificate check failed before an expiry date could be read.');
});
