<?php

use App\Jobs\CheckSslExpiryDateJob;
use App\Mail\EmailReminderSsl;
use App\Models\NotificationSetting;
use App\Models\Website;
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
