<?php

namespace App\Services;

use Carbon\CarbonInterface;

class PackageHealthStatusService
{
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

    public function summaryForApi(array $result, ?int $expectedStatus = null): string
    {
        $status = $this->apiStatusFromResult($result, $expectedStatus);
        $code = $result['code'] ?? 0;

        if ($status === 'danger') {
            return "API heartbeat failed with HTTP status {$code}.";
        }

        if ($status === 'warning') {
            return "API heartbeat is degraded with HTTP status {$code}.";
        }

        return "API heartbeat succeeded with HTTP status {$code}.";
    }

    public function staleSummary(string $interval): string
    {
        return "No heartbeat received within the expected {$interval} interval.";
    }

    public function isStale(?CarbonInterface $lastHeartbeatAt, ?string $interval): bool
    {
        if ($lastHeartbeatAt === null || blank($interval)) {
            return false;
        }

        return $lastHeartbeatAt->lt(now()->subMinutes($this->intervalToMinutes($interval)));
    }

    public function intervalToMinutes(string $interval): int
    {
        return IntervalParser::toMinutes($interval);
    }
}
