<?php

namespace App\Services;

use App\Models\Website;
use Carbon\CarbonInterface;
use Spatie\SslCertificate\SslCertificate;

class SslCertificateService
{
    public function extractHost(?string $url): ?string
    {
        return Website::extractHost($url);
    }

    public function extractPort(?string $url, int $default = 443): int
    {
        return Website::extractPort($url, $default);
    }

    public function getExpirationDateForHost(string $host, int $port = 443): CarbonInterface
    {
        return SslCertificate::download()
            ->usingPort($port)
            ->forHost($host)
            ->expirationDate();
    }
}
