<?php

namespace App\Support;

use App\Services\IntervalParser;
use Carbon\CarbonInterface;

class PackageCheckTableEvidence
{
    public const STATE_DISABLED = 'Disabled';

    public const STATE_FRESH = 'Fresh';

    public const STATE_AWAITING_HEARTBEAT = 'Awaiting heartbeat';

    public const STATE_STALE = 'Stale';

    public const STATE_SCHEDULE_UNKNOWN = 'Schedule unknown';

    public static function freshnessState(object $record): string
    {
        if (static::isMonitoringDisabled($record)) {
            return self::STATE_DISABLED;
        }

        if (blank($record->package_interval)) {
            return self::STATE_SCHEDULE_UNKNOWN;
        }

        if ($record->last_heartbeat_at === null) {
            return self::STATE_AWAITING_HEARTBEAT;
        }

        $thresholdAt = static::staleThresholdAt($record);

        if ($thresholdAt === null) {
            return $record->stale_at !== null ? self::STATE_STALE : self::STATE_SCHEDULE_UNKNOWN;
        }

        if ($thresholdAt->lt(now()) || $record->stale_at !== null) {
            return self::STATE_STALE;
        }

        return self::STATE_FRESH;
    }

    public static function freshnessColor(string $state): string
    {
        return match ($state) {
            self::STATE_FRESH => 'success',
            self::STATE_AWAITING_HEARTBEAT => 'warning',
            self::STATE_STALE => 'danger',
            default => 'gray',
        };
    }

    public static function freshnessDescription(object $record): ?string
    {
        if (static::isMonitoringDisabled($record)) {
            return 'Monitor is disabled. Heartbeats are not expected.';
        }

        if (blank($record->package_interval)) {
            return 'No package interval configured yet.';
        }

        $interval = static::displayInterval($record->package_interval);

        if ($record->last_heartbeat_at === null) {
            return "Expected every {$interval}.";
        }

        $thresholdAt = static::staleThresholdAt($record);

        if ($thresholdAt === null) {
            return $record->stale_at !== null
                ? 'Expired '.$record->stale_at->diffForHumans().'.'
                : "Package interval {$record->package_interval} cannot be evaluated.";
        }

        if ($thresholdAt->lt(now()) || $record->stale_at !== null) {
            $referenceTime = $record->stale_at ?? $thresholdAt;

            return 'Expired '.$referenceTime->diffForHumans().'.';
        }

        return 'Expires '.$thresholdAt->diffForHumans().'.';
    }

    public static function staleThresholdAt(object $record): ?CarbonInterface
    {
        if ($record->last_heartbeat_at === null || blank($record->package_interval)) {
            return null;
        }

        try {
            return $record->last_heartbeat_at->copy()->addMinutes(IntervalParser::toMinutes($record->package_interval));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function displayInterval(?string $interval): ?string
    {
        if (blank($interval)) {
            return null;
        }

        try {
            return IntervalParser::fromMinutes(IntervalParser::toMinutes($interval));
        } catch (\InvalidArgumentException) {
            return $interval;
        }
    }

    private static function isMonitoringDisabled(object $record): bool
    {
        return ($record->is_enabled ?? true) === false;
    }
}
