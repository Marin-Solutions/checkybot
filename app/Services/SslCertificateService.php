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
            ->forHost($this->formatHostForDownload($host, $port))
            ->expirationDate();
    }

    public static function expiryDateChanged(?CarbonInterface $currentExpiryDate, CarbonInterface $newExpiryDate): bool
    {
        return $currentExpiryDate === null || ! $currentExpiryDate->isSameDay($newExpiryDate);
    }

    private function formatHostForDownload(string $host, int $port): string
    {
        $formattedHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? "[{$host}]"
            : $host;

        return "{$formattedHost}:{$port}";
    }
}
