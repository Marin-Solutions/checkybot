<?php

namespace App\Support;

use App\Services\IntervalParser;
use Carbon\CarbonInterface;

class PackageCheckTableEvidence
{
    public static function freshnessState(object $record): string
    {
        if (static::isMonitoringDisabled($record)) {
            return 'Disabled';
        }

        if (blank($record->package_interval)) {
            return 'Schedule unknown';
        }

        if ($record->last_heartbeat_at === null) {
            return 'Awaiting heartbeat';
        }

        $thresholdAt = static::staleThresholdAt($record);

        if ($thresholdAt === null) {
            return $record->stale_at !== null ? 'Stale' : 'Schedule unknown';
        }

        if ($thresholdAt->lt(now()) || $record->stale_at !== null) {
            return 'Stale';
        }

        return 'Fresh';
    }

    public static function freshnessColor(string $state): string
    {
        return match ($state) {
            'Fresh' => 'success',
            'Awaiting heartbeat' => 'warning',
            'Stale' => 'danger',
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
