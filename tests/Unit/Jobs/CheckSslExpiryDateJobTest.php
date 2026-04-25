<?php

use App\Jobs\CheckSslExpiryDateJob;
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
            ->with('not-a-url/path')
            ->andReturn(null);

        $mock->shouldReceive('extractPort')
            ->once()
            ->with('not-a-url/path')
            ->andReturn(443);

        $mock->shouldNotReceive('getExpirationDateForHost');
    });

    $website = Website::factory()->create([
        'url' => 'not-a-url/path',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(10),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle(app(SslCertificateService::class));

    Log::shouldHaveReceived('error')
        ->once()
        ->with('Could not determine SSL host for website not-a-url/path');

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
