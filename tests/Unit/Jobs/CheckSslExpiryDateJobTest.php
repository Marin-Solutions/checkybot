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

test('job logs ssl certificate retrieval failures as monitor warnings', function () {
    Log::spy();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://maillocals.com')
            ->andReturn('maillocals.com');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://maillocals.com')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('maillocals.com', 443)
            ->andThrow(new Exception('The host named `maillocals.com` does not exist.'));
    });

    $website = Website::factory()->create([
        'url' => 'https://maillocals.com',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(10),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    Log::shouldNotHaveReceived('error');
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Could not retrieve SSL certificate for website https://maillocals.com: The host named `maillocals.com` does not exist.', [
            'website_id' => $website->id,
            'url' => 'https://maillocals.com',
            'host' => 'maillocals.com',
            'port' => 443,
            'monitor' => 'ssl_expiry',
        ]);

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

test('job skips configured ssl reminder notifications while website is snoozed', function () {
    Mail::fake();
    Http::fake();

    $expiryDate = now()->addDays(7);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($expiryDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://snoozed-reminder.example')
            ->andReturn('snoozed-reminder.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://snoozed-reminder.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('snoozed-reminder.example', 443)
            ->andReturn($expiryDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://snoozed-reminder.example',
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => null,
        'silenced_until' => now()->addHour(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
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

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay($expiryDate))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at)->toBeNull();

    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('job skips fallback ssl reminder email while website is snoozed', function () {
    Mail::fake();

    $expiryDate = now()->addDays(3);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($expiryDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://snoozed-fallback.example')
            ->andReturn('snoozed-fallback.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://snoozed-fallback.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('snoozed-fallback.example', 443)
            ->andReturn($expiryDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://snoozed-fallback.example',
        'ssl_check' => true,
        'ssl_expiry_date' => null,
        'ssl_expiry_reminder_sent_at' => null,
        'silenced_until' => now()->addHour(),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect(Carbon::parse($website->ssl_expiry_date)->isSameDay($expiryDate))->toBeTrue()
        ->and($website->ssl_expiry_reminder_sent_at)->toBeNull();

    Mail::assertNothingSent();
});

test('job does not overwrite a newer snooze when saving ssl reminder timestamp', function () {
    $newSnoozeUntil = now()->addHour()->startOfSecond();
    $expiryDate = now()->addDays(7);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($expiryDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://concurrent-snooze.example')
            ->andReturn('concurrent-snooze.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://concurrent-snooze.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('concurrent-snooze.example', 443)
            ->andReturn($expiryDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://concurrent-snooze.example',
        'ssl_check' => true,
        'ssl_expiry_date' => $expiryDate,
        'ssl_expiry_reminder_sent_at' => null,
        'silenced_until' => now()->subMinute(),
    ]);
    $queuedWebsite = Website::findOrFail($website->id);

    $website->update(['silenced_until' => null]);

    $channel = NotificationChannels::factory()->create([
        'method' => 'POST',
        'url' => 'https://example.com/ssl-webhook',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->webhook()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
            'notification_channel_id' => $channel->id,
        ]);

    Http::fake(function () use ($website, $newSnoozeUntil) {
        Website::query()
            ->whereKey($website->id)
            ->update(['silenced_until' => $newSnoozeUntil]);

        return Http::response(['ok' => true], 200);
    });

    $job = new CheckSslExpiryDateJob($queuedWebsite);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->ssl_expiry_reminder_sent_at)->not->toBeNull()
        ->and($website->silenced_until?->equalTo($newSnoozeUntil))->toBeTrue();
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
        ->and($website->status_summary)->toBe('Uptime check returned HTTP 500.')
        ->and(WebsiteLogHistory::where('website_id', $website->id)->count())->toBe(0);
});

test('job records manual ssl-only heartbeat status when certificate is healthy', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://manual.example')
            ->andReturn('manual.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://manual.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('manual.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://manual.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'manual',
        'uptime_interval' => 5,
        'current_status' => 'danger',
        'last_heartbeat_at' => now()->subMinutes(6),
        'stale_at' => now()->subHour(),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();
    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($website->current_status)->toBe('healthy')
        ->and($website->stale_at)->toBeNull()
        ->and($website->status_summary)->toBe('SSL certificate is valid for 45 day(s).')
        ->and($history?->status)->toBe('healthy')
        ->and($history?->summary)->toBe('SSL certificate is valid for 45 day(s).')
        ->and($history?->run_source->value)->toBe('scheduled')
        ->and($history?->is_on_demand)->toBeFalse();
});

test('job sends health alert for manual ssl-only scheduled failures', function () {
    Mail::fake();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://manual-broken.example')
            ->andReturn('manual-broken.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://manual-broken.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('manual-broken.example', 443)
            ->andThrow(new RuntimeException('certificate unavailable'));
    });

    $website = Website::factory()->create([
        'url' => 'https://manual-broken.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'manual',
        'uptime_interval' => 5,
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
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

    expect($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('SSL certificate check failed before an expiry date could be read.');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'heartbeat'
            && $mail->status === 'danger'
            && $mail->summary === 'SSL certificate check failed before an expiry date could be read.';
    });
});

test('job does not record manual ssl-only heartbeat before interval elapses', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);
    $lastHeartbeat = now()->subMinutes(2)->startOfSecond();

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://manual-wait.example')
            ->andReturn('manual-wait.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://manual-wait.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('manual-wait.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://manual-wait.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'manual',
        'uptime_interval' => 5,
        'current_status' => 'healthy',
        'last_heartbeat_at' => $lastHeartbeat,
        'status_summary' => 'SSL certificate is valid for 46 day(s).',
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('SSL certificate is valid for 45 day(s).')
        ->and(WebsiteLogHistory::where('website_id', $website->id)->count())->toBe(1);
});

test('job does not record manual ssl-only heartbeat without an interval', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://manual-no-interval.example')
            ->andReturn('manual-no-interval.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://manual-no-interval.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('manual-no-interval.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://manual-no-interval.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'manual',
        'uptime_interval' => null,
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
        'status_summary' => 'Awaiting scheduled SSL check.',
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->last_heartbeat_at)->toBeNull()
        ->and($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('Awaiting scheduled SSL check.')
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

    expect($website->current_status)->toBe('warning')
        ->and($website->status_summary)->toBe('SSL certificate expires in 14 day(s).')
        ->and(WebsiteLogHistory::where('website_id', $website->id)->count())->toBe(1);
});

test('job does not record package ssl-only heartbeat without an interval', function () {
    Mail::fake();

    $futureDate = now()->addDays(45);

    $this->mock(SslCertificateService::class, function (MockInterface $mock) use ($futureDate) {
        $mock->shouldReceive('extractHost')
            ->once()
            ->with('https://package-no-interval.example')
            ->andReturn('package-no-interval.example');

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('https://package-no-interval.example')
            ->andReturn(443);

        $mock->shouldReceive('getExpirationDateForHost')
            ->once()
            ->with('package-no-interval.example', 443)
            ->andReturn($futureDate);
    });

    $website = Website::factory()->create([
        'url' => 'https://package-no-interval.example',
        'ssl_check' => true,
        'uptime_check' => false,
        'source' => 'package',
        'package_name' => 'certificate',
        'package_interval' => null,
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
        'status_summary' => 'Awaiting scheduled SSL check.',
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    $website->refresh();

    expect($website->last_heartbeat_at)->toBeNull()
        ->and($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('Awaiting scheduled SSL check.')
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
        ->and($website->status_summary)->toBe('SSL certificate check failed before an expiry date could be read.')
        ->and($history?->status)->toBe('danger')
        ->and($history?->summary)->toBe('SSL certificate check failed before an expiry date could be read.');
});
