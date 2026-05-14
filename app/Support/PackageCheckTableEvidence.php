<?php

namespace App\Support;

use App\Services\IntervalParser;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class PackageCheckTableEvidence
{
    public const STATE_DISABLED = 'Disabled';

    public const STATE_FRESH = 'Fresh';

    public const STATE_AWAITING_HEARTBEAT = 'Awaiting heartbeat';

    public const STATE_HEARTBEAT_RECEIVED = 'Heartbeat received';

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

        $thresholdAt = static::staleThresholdAt($record);

        if ($thresholdAt === null) {
            return $record->stale_at !== null ? self::STATE_STALE : self::STATE_SCHEDULE_UNKNOWN;
        }

        if ($thresholdAt->lt(now()) || $record->stale_at !== null) {
            return self::STATE_STALE;
        }

        if ($record->last_heartbeat_at === null) {
            return self::STATE_AWAITING_HEARTBEAT;
        }

        return self::STATE_FRESH;
    }

    public static function freshnessColor(string $state): string
    {
        return match ($state) {
            self::STATE_FRESH,
            self::STATE_HEARTBEAT_RECEIVED => 'success',
            self::STATE_AWAITING_HEARTBEAT => 'warning',
            self::STATE_STALE => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function freshnessFilterOptions(): array
    {
        return [
            self::STATE_STALE => self::STATE_STALE,
            self::STATE_AWAITING_HEARTBEAT => self::STATE_AWAITING_HEARTBEAT,
            self::STATE_FRESH => self::STATE_FRESH,
            self::STATE_DISABLED => self::STATE_DISABLED,
        ];
    }

    public static function applyWebsiteFreshnessFilter(Builder $query, ?string $state): Builder
    {
        return static::applyFreshnessFilter(
            $query,
            $state,
            disabledScope: fn (Builder $query): Builder => $query
                ->where('uptime_check', false)
                ->where('ssl_check', false),
            activeScope: fn (Builder $query): Builder => $query
                ->where(fn (Builder $query) => $query
                    ->where('uptime_check', true)
                    ->orWhere('ssl_check', true)),
        );
    }

    public static function applyApiFreshnessFilter(Builder $query, ?string $state): Builder
    {
        return static::applyFreshnessFilter(
            $query,
            $state,
            disabledScope: fn (Builder $query): Builder => $query->where('is_enabled', false),
            activeScope: fn (Builder $query): Builder => $query->where('is_enabled', true),
        );
    }

    public static function mainMonitorFreshnessState(object $record): string
    {
        if (($record->source ?? null) === 'package') {
            return static::freshnessState($record);
        }

        if (static::isMonitoringDisabled($record)) {
            return self::STATE_DISABLED;
        }

        if ($record->stale_at !== null) {
            return self::STATE_STALE;
        }

        if ($record->last_heartbeat_at === null) {
            return self::STATE_AWAITING_HEARTBEAT;
        }

        return self::STATE_HEARTBEAT_RECEIVED;
    }

    public static function mainMonitorFreshnessColor(string $state): string
    {
        return static::freshnessColor($state);
    }

    public static function mainMonitorFreshnessDescription(object $record): ?string
    {
        if (($record->source ?? null) === 'package') {
            return static::freshnessDescription($record);
        }

        if (static::isMonitoringDisabled($record)) {
            return 'Monitor is disabled. Heartbeats are not expected.';
        }

        if ($record->stale_at !== null) {
            return 'Marked stale '.$record->stale_at->diffForHumans().'.';
        }

        if ($record->last_heartbeat_at === null) {
            return 'No scheduled heartbeat has been recorded yet.';
        }

        return 'Last heartbeat '.$record->last_heartbeat_at->diffForHumans().'.';
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

        if ($record->last_heartbeat_at === null) {
            return "Expected every {$interval}.";
        }

        return 'Expires '.$thresholdAt->diffForHumans().'.';
    }

    public static function staleThresholdAt(object $record): ?CarbonInterface
    {
        if (blank($record->package_interval)) {
            return null;
        }

        $anchorAt = $record->last_heartbeat_at
            ?? static::attributeValue($record, 'awaiting_heartbeat_since')
            ?? static::attributeValue($record, 'created_at');

        if ($anchorAt === null) {
            return null;
        }

        try {
            return $anchorAt->copy()->addMinutes(IntervalParser::toMinutes($record->package_interval));
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

        $nextDueAt = static::staleThresholdAt($record);

        if ($nextDueAt === null) {
            return self::DUE_STATE_INVALID;
        }

        if ($nextDueAt->lte(now())) {
            return self::DUE_STATE_DUE;
        }

        return $record->last_heartbeat_at === null
            ? self::DUE_STATE_AWAITING_FIRST_RUN
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

        $nextDueAt = static::staleThresholdAt($record);

        if ($nextDueAt === null) {
            return "Schedule value {$record->package_interval} cannot be evaluated. This monitor may run on every scheduler minute until fixed.";
        }

        if ($nextDueAt->lte(now())) {
            return "Overdue {$nextDueAt->diffForHumans()}. Expected every {$interval}.";
        }

        if ($record->last_heartbeat_at === null) {
            return "First scheduled run will happen on the next scheduler pass, then continue every {$interval}.";
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
        if (static::attributeValue($record, 'is_enabled', true) === false) {
            return true;
        }

        $uptimeCheck = static::attributeValue($record, 'uptime_check');
        $sslCheck = static::attributeValue($record, 'ssl_check');

        if ($uptimeCheck === null || $sslCheck === null) {
            return false;
        }

        return in_array($uptimeCheck, [false, 0, '0'], true)
            && in_array($sslCheck, [false, 0, '0'], true);
    }

    private static function applyFreshnessFilter(
        Builder $query,
        ?string $state,
        Closure $disabledScope,
        Closure $activeScope,
    ): Builder {
        if ($state === null || $state === '') {
            return $query;
        }

        if ($state === self::STATE_DISABLED) {
            return $query->where(fn (Builder $query): Builder => $disabledScope($query));
        }

        if (! array_key_exists($state, static::freshnessFilterOptions())) {
            return $query;
        }

        $query = $activeScope($query);

        return match ($state) {
            self::STATE_STALE => static::applyStaleFreshnessFilter($query),
            self::STATE_AWAITING_HEARTBEAT => static::applyAwaitingHeartbeatFreshnessFilter($query),
            self::STATE_FRESH => static::applyFreshFreshnessFilter($query),
            default => $query,
        };
    }

    private static function applyStaleFreshnessFilter(Builder $query): Builder
    {
        [$overdueSql, $bindings] = PackageIntervalDueExpression::build($query->getConnection(), '<');

        return static::whereHasPackageInterval($query)
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('stale_at')
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereNotNull('last_heartbeat_at')
                    ->where(fn (Builder $query): Builder => $query->whereRaw($overdueSql, $bindings)))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereNull('last_heartbeat_at')
                    ->where(fn (Builder $query): Builder => static::whereFirstHeartbeatInterval($query, '<'))));
    }

    private static function applyAwaitingHeartbeatFreshnessFilter(Builder $query): Builder
    {
        return static::whereHasPackageInterval($query)
            ->whereNull('last_heartbeat_at')
            ->whereNull('stale_at')
            ->where(fn (Builder $query): Builder => static::whereFirstHeartbeatInterval($query, '>='));
    }

    private static function applyFreshFreshnessFilter(Builder $query): Builder
    {
        [$freshSql, $bindings] = PackageIntervalDueExpression::build($query->getConnection(), '>=');

        return static::whereHasPackageInterval($query)
            ->whereNotNull('last_heartbeat_at')
            ->whereNull('stale_at')
            ->where(fn (Builder $query): Builder => $query->whereRaw($freshSql, $bindings));
    }

    private static function whereHasPackageInterval(Builder $query): Builder
    {
        return $query
            ->whereNotNull('package_interval')
            ->where('package_interval', '!=', '');
    }

    private static function whereFirstHeartbeatInterval(Builder $query, string $operator): Builder
    {
        [$resetSql, $resetBindings] = PackageIntervalDueExpression::build($query->getConnection(), $operator, 'awaiting_heartbeat_since');
        [$createdSql, $createdBindings] = PackageIntervalDueExpression::build($query->getConnection(), $operator, 'created_at');

        return $query
            ->where(function (Builder $query) use ($resetSql, $resetBindings): void {
                $query->whereNotNull('awaiting_heartbeat_since')
                    ->whereRaw($resetSql, $resetBindings);
            })
            ->orWhere(function (Builder $query) use ($createdSql, $createdBindings): void {
                $query->whereNull('awaiting_heartbeat_since')
                    ->whereRaw($createdSql, $createdBindings);
            });
    }

    private static function attributeValue(object $record, string $attribute, mixed $default = null): mixed
    {
        if (method_exists($record, 'getAttribute')) {
            return $record->getAttribute($attribute) ?? $default;
        }

        return $record->{$attribute} ?? $default;
    }
}
