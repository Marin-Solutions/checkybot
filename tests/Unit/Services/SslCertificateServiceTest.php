<?php

use App\Services\SslCertificateService;

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
