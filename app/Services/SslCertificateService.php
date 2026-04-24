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

    public function getExpirationDateForHost(string $host): CarbonInterface
    {
        return SslCertificate::createForHostName($host)->expirationDate();
    }
}
