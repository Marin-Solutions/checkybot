<?php

use App\Services\SslCertificateService;
use Carbon\Carbon;

test('formatHostForDownload appends custom ports to hostnames', function () {
    $service = app(SslCertificateService::class);
    $method = new ReflectionMethod($service, 'formatHostForDownload');
    $method->setAccessible(true);

    expect($method->invoke($service, 'example.com', 8443))->toBe('example.com:8443');
});

test('formatHostForDownload wraps ipv6 hosts before appending custom ports', function () {
    $service = app(SslCertificateService::class);
    $method = new ReflectionMethod($service, 'formatHostForDownload');
    $method->setAccessible(true);

    expect($method->invoke($service, '2001:db8::1', 8443))->toBe('[2001:db8::1]:8443');
});

test('expiryDateChanged compares expiry dates by day', function () {
    expect(SslCertificateService::expiryDateChanged(null, Carbon::parse('2026-05-01 09:00:00')))->toBeTrue()
        ->and(SslCertificateService::expiryDateChanged(
            Carbon::parse('2026-05-01 09:00:00'),
            Carbon::parse('2026-05-01 18:00:00'),
        ))->toBeFalse()
        ->and(SslCertificateService::expiryDateChanged(
            Carbon::parse('2026-05-01 09:00:00'),
            Carbon::parse('2026-05-02 09:00:00'),
        ))->toBeTrue();
});
