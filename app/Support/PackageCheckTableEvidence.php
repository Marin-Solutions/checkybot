<?php

namespace App\Support;

use App\Services\IntervalParser;
use Carbon\CarbonInterface;

class PackageCheckTableEvidence
{
    public static function freshnessState(object $record): string
    {
        if (blank($record->package_interval)) {
            return 'Schedule unknown';
        }

        if ($record->last_heartbeat_at === null) {
            return 'Awaiting heartbeat';
        }

        if (static::staleThresholdAt($record)?->lte(now()) || $record->stale_at !== null) {
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
        $interval = static::displayInterval($record->package_interval);

        if (blank($record->package_interval)) {
            return 'No package interval configured yet.';
        }

        if ($record->last_heartbeat_at === null) {
            return $interval ? "Expected every {$interval}." : 'No heartbeat received yet.';
        }

        $thresholdAt = static::staleThresholdAt($record);

        if ($thresholdAt === null) {
            return "Package interval {$record->package_interval} cannot be evaluated.";
        }

        if ($thresholdAt->lte(now()) || $record->stale_at !== null) {
            return 'Expired '.$thresholdAt->diffForHumans().'.';
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
            return IntervalParser::normalize($interval);
        } catch (\InvalidArgumentException) {
            return $interval;
        }
    }
}
