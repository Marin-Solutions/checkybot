<?php

namespace App\Services;

use App\Support\UptimeTransportError;
use Carbon\CarbonInterface;

class PackageHealthStatusService
{
    private const STATUS_SEVERITY = [
        'healthy' => 0,
        'warning' => 1,
        'danger' => 2,
    ];

    public function websiteStatusFromHttpCode(?int $httpCode): string
    {
        return match (true) {
            $httpCode === null => 'warning',
            $httpCode === 0 => 'danger',
            $httpCode >= 500 => 'danger',
            $httpCode >= 400 => 'warning',
            default => 'healthy',
        };
    }

    public function apiStatusFromResult(array $result, ?int $expectedStatus = null): string
    {
        $code = $result['code'] ?? null;
        $hasFailedAssertions = collect($result['assertions'] ?? [])
            ->contains(fn (array $assertion): bool => ! ($assertion['passed'] ?? false));

        if ($code === 0) {
            return 'danger';
        }

        if ($expectedStatus !== null && $code === $expectedStatus) {
            return $hasFailedAssertions ? 'warning' : 'healthy';
        }

        if ($expectedStatus !== null && $code !== null) {
            return match (true) {
                $code >= 500 => 'danger',
                default => 'warning',
            };
        }

        return match (true) {
            ($code ?? 200) >= 500 => 'danger',
            ($code ?? 200) >= 400 => 'warning',
            $hasFailedAssertions => 'warning',
            default => 'healthy',
        };
    }

    public function summaryForWebsite(?int $httpCode): string
    {
        return match ($this->websiteStatusFromHttpCode($httpCode)) {
            'danger' => "Website heartbeat failed with HTTP status {$httpCode}.",
            'warning' => "Website heartbeat is degraded with HTTP status {$httpCode}.",
            default => "Website heartbeat succeeded with HTTP status {$httpCode}.",
        };
    }

    public function worstStatus(string ...$statuses): string
    {
        $worstStatus = 'healthy';
        $worstSeverity = self::STATUS_SEVERITY[$worstStatus];

        foreach ($statuses as $status) {
            $severity = self::STATUS_SEVERITY[$status] ?? self::STATUS_SEVERITY['warning'];

            if ($severity > $worstSeverity) {
                $worstStatus = $status;
                $worstSeverity = $severity;
            }
        }

        return $worstStatus;
    }

    public function websiteStatusFromHttpAndSsl(?int $httpCode, ?CarbonInterface $sslExpiryDate): string
    {
        return $this->worstStatus(
            $this->websiteStatusFromHttpCode($httpCode),
            $this->sslStatusFromExpiryDate($sslExpiryDate),
        );
    }

    public function sslStatusFromExpiryDate(?CarbonInterface $expiryDate): string
    {
        if ($expiryDate === null) {
            return 'danger';
        }

        if ($expiryDate->isPast()) {
            return 'danger';
        }

        $daysLeft = today()->diffInDays($expiryDate->copy()->startOfDay(), false);

        return match (true) {
            $daysLeft <= 14 => 'warning',
            default => 'healthy',
        };
    }

    public function summaryForSsl(?CarbonInterface $expiryDate): string
    {
        if ($expiryDate === null) {
            return 'SSL certificate check failed before an expiry date could be read.';
        }

        if ($expiryDate->isPast()) {
            $daysExpired = abs((int) today()->diffInDays($expiryDate->copy()->startOfDay(), false));

            return $daysExpired === 0
                ? 'SSL certificate expired today.'
                : "SSL certificate expired {$daysExpired} day(s) ago.";
        }

        $daysLeft = today()->diffInDays($expiryDate->copy()->startOfDay(), false);

        if ($daysLeft <= 14) {
            return "SSL certificate expires in {$daysLeft} day(s).";
        }

        return "SSL certificate is valid for {$daysLeft} day(s).";
    }

    public function summaryForApi(array $result, ?int $expectedStatus = null): string
    {
        $status = $this->apiStatusFromResult($result, $expectedStatus);
        $code = $result['code'] ?? 0;

        if ($status === 'danger') {
            if ($code === 0 && filled($result['transport_error_type'] ?? null)) {
                return UptimeTransportError::summary($result['transport_error_type'], 'API heartbeat');
            }

            return "API heartbeat failed with HTTP status {$code}.";
        }

        if ($status === 'warning') {
            return "API heartbeat is degraded with HTTP status {$code}.";
        }

        return "API heartbeat succeeded with HTTP status {$code}.";
    }

    public function staleSummary(string $interval): string
    {
        try {
            $displayInterval = IntervalParser::normalize($interval);
        } catch (\InvalidArgumentException) {
            $displayInterval = $interval;
        }

        return "No heartbeat received within the expected {$displayInterval} interval.";
    }

    public function isStale(?CarbonInterface $lastHeartbeatAt, ?string $interval): bool
    {
        if ($lastHeartbeatAt === null || blank($interval)) {
            return false;
        }

        try {
            return $lastHeartbeatAt->lt(now()->subMinutes($this->intervalToMinutes($interval)));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function intervalToMinutes(string $interval): int
    {
        return IntervalParser::toMinutes($interval);
    }
}
