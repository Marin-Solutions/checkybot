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

    public const DUE_STATE_DISABLED = 'Paused';

    public const DUE_STATE_MISSING = 'Schedule required';

    public const DUE_STATE_INVALID = 'Schedule invalid';

    public const DUE_STATE_AWAITING_FIRST_RUN = 'Awaiting first run';

    public const DUE_STATE_DUE = 'Due now';

    public const DUE_STATE_SCHEDULED = 'Scheduled';

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

    public static function dueState(object $record): string
    {
        if (static::isMonitoringDisabled($record)) {
            return self::DUE_STATE_DISABLED;
        }

        if (blank($record->package_interval)) {
            return self::DUE_STATE_MISSING;
        }

        if ($record->last_heartbeat_at === null) {
            return self::DUE_STATE_AWAITING_FIRST_RUN;
        }

        $nextDueAt = static::staleThresholdAt($record);

        if ($nextDueAt === null) {
            return self::DUE_STATE_INVALID;
        }

        return $nextDueAt->lte(now())
            ? self::DUE_STATE_DUE
            : self::DUE_STATE_SCHEDULED;
    }

    public static function dueStateColor(string $state): string
    {
        return match ($state) {
            self::DUE_STATE_SCHEDULED => 'success',
            self::DUE_STATE_DUE,
            self::DUE_STATE_AWAITING_FIRST_RUN => 'warning',
            self::DUE_STATE_MISSING,
            self::DUE_STATE_INVALID => 'danger',
            default => 'gray',
        };
    }

    public static function dueDescription(object $record): string
    {
        if (static::isMonitoringDisabled($record)) {
            return 'Scheduled checks are paused until this monitor is re-enabled.';
        }

        if (blank($record->package_interval)) {
            return 'No polling interval is configured. Legacy monitors without a schedule can run on every scheduler minute.';
        }

        $interval = static::displayInterval($record->package_interval) ?? $record->package_interval;

        if ($record->last_heartbeat_at === null) {
            return "First scheduled run will happen on the next scheduler pass, then continue every {$interval}.";
        }

        $nextDueAt = static::staleThresholdAt($record);

        if ($nextDueAt === null) {
            return "Schedule value {$record->package_interval} cannot be evaluated. This monitor may run on every scheduler minute until fixed.";
        }

        if ($nextDueAt->lte(now())) {
            return "Overdue {$nextDueAt->diffForHumans()}. Expected every {$interval}.";
        }

        return "Next scheduled run {$nextDueAt->diffForHumans()}. Expected every {$interval}.";
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
