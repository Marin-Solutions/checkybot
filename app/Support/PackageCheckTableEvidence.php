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

    public const STATE_AWAITING_HEARTBEAT = 'Awaiting check';

    public const STATE_AWAITING_CHECK = 'Awaiting check';

    public const STATE_HEARTBEAT_RECEIVED = 'Check received';

    public const STATE_CHECK_RECEIVED = 'Check received';

    public const STATE_STALE = 'Due now';

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

        $thresholdAt = static::nextDueAt($record);

        if ($thresholdAt === null) {
            return self::STATE_SCHEDULE_UNKNOWN;
        }

        if ($thresholdAt->lt(now())) {
            return self::STATE_STALE;
        }

        if (static::latestRunAt($record) === null) {
            return self::STATE_AWAITING_HEARTBEAT;
        }

        return self::STATE_FRESH;
    }

    public static function freshnessColor(string $state): string
    {
        return match ($state) {
            self::STATE_FRESH,
            self::STATE_HEARTBEAT_RECEIVED,
            self::STATE_CHECK_RECEIVED => 'success',
            self::STATE_AWAITING_HEARTBEAT,
            self::STATE_AWAITING_CHECK => 'warning',
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
        $state = match ($state) {
            self::STATE_AWAITING_CHECK => self::STATE_AWAITING_HEARTBEAT,
            self::STATE_CHECK_RECEIVED => self::STATE_HEARTBEAT_RECEIVED,
            default => $state,
        };

        return static::applyFreshnessFilter(
            $query,
            $state,
            disabledScope: fn (Builder $query): Builder => $query->where('is_enabled', false),
            activeScope: fn (Builder $query): Builder => $query->where('is_enabled', true),
        );
    }

    public static function apiFreshnessState(object $record): string
    {
        return static::apiStateLabel(static::freshnessState($record));
    }

    public static function mainApiFreshnessState(object $record): string
    {
        if (($record->source ?? null) === 'package') {
            return static::apiFreshnessState($record);
        }

        return static::apiStateLabel(static::mainMonitorFreshnessState($record));
    }

    public static function apiFreshnessDescription(object $record): ?string
    {
        return static::apiDescription(static::freshnessDescription($record));
    }

    public static function mainApiFreshnessDescription(object $record): ?string
    {
        if (($record->source ?? null) === 'package') {
            return static::apiFreshnessDescription($record);
        }

        if (static::isMonitoringDisabled($record)) {
            return 'Monitor is disabled. Scheduled API checks are not expected.';
        }

        if (static::latestRunAt($record) === null) {
            return 'No scheduled API check has been recorded yet.';
        }

        return 'Last scheduled API check '.static::latestRunAt($record)?->diffForHumans().'.';
    }

    /**
     * @return array<string, string>
     */
    public static function apiFreshnessFilterOptions(): array
    {
        return [
            self::STATE_STALE => self::STATE_STALE,
            self::STATE_AWAITING_CHECK => self::STATE_AWAITING_CHECK,
            self::STATE_FRESH => self::STATE_FRESH,
            self::STATE_DISABLED => self::STATE_DISABLED,
        ];
    }

    public static function mainMonitorFreshnessState(object $record): string
    {
        if (($record->source ?? null) === 'package') {
            return static::freshnessState($record);
        }

        if (static::isMonitoringDisabled($record)) {
            return self::STATE_DISABLED;
        }

        if (static::latestRunAt($record) === null) {
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
            return 'Monitor is disabled. Scheduled checks are not expected.';
        }

        if (static::latestRunAt($record) === null) {
            return 'No scheduled check has been recorded yet.';
        }

        return 'Last scheduled check '.static::latestRunAt($record)?->diffForHumans().'.';
    }

    public static function freshnessDescription(object $record): ?string
    {
        if (static::isMonitoringDisabled($record)) {
            return 'Monitor is disabled. Scheduled checks are not expected.';
        }

        if (blank($record->package_interval)) {
            return 'No package interval configured yet.';
        }

        $interval = static::displayInterval($record->package_interval);

        $thresholdAt = static::nextDueAt($record);

        if ($thresholdAt === null) {
            return "Package interval {$record->package_interval} cannot be evaluated.";
        }

        if ($thresholdAt->lt(now())) {
            return 'Due '.$thresholdAt->diffForHumans().'.';
        }

        if (static::latestRunAt($record) === null) {
            return "Expected every {$interval}.";
        }

        return 'Expires '.$thresholdAt->diffForHumans().'.';
    }

    public static function staleThresholdAt(object $record): ?CarbonInterface
    {
        return static::nextDueAt($record);
    }

    public static function nextDueAt(object $record): ?CarbonInterface
    {
        if (blank($record->package_interval)) {
            return null;
        }

        $anchorAt = static::latestRunAt($record) ?? static::attributeValue($record, 'created_at');

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

        $nextDueAt = static::nextDueAt($record);

        if ($nextDueAt === null) {
            return self::DUE_STATE_INVALID;
        }

        if ($nextDueAt->lte(now())) {
            return self::DUE_STATE_DUE;
        }

        return static::latestRunAt($record) === null
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

        $nextDueAt = static::nextDueAt($record);

        if ($nextDueAt === null) {
            return "Schedule value {$record->package_interval} cannot be evaluated. This monitor may run on every scheduler minute until fixed.";
        }

        if ($nextDueAt->lte(now())) {
            return "Overdue {$nextDueAt->diffForHumans()}. Expected every {$interval}.";
        }

        if (static::latestRunAt($record) === null) {
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

    private static function apiStateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_AWAITING_HEARTBEAT => self::STATE_AWAITING_CHECK,
            self::STATE_HEARTBEAT_RECEIVED => self::STATE_CHECK_RECEIVED,
            default => $state,
        };
    }

    private static function apiDescription(?string $description): ?string
    {
        return match ($description) {
            'Monitor is disabled. Heartbeats are not expected.' => 'Monitor is disabled. Scheduled API checks are not expected.',
            'Monitor is disabled. Scheduled checks are not expected.' => 'Monitor is disabled. Scheduled API checks are not expected.',
            'No scheduled heartbeat has been recorded yet.' => 'No scheduled API check has been recorded yet.',
            'No scheduled check has been recorded yet.' => 'No scheduled API check has been recorded yet.',
            default => $description === null
                ? null
                : str_replace('Last scheduled check ', 'Last scheduled API check ', str_replace('Last heartbeat ', 'Last scheduled API check ', $description)),
        };
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
        $latestRunAtSql = static::latestScheduledRunAtSql($query);

        if ($latestRunAtSql === null) {
            return static::whereHasPackageInterval($query);
        }

        [$dueSql, $bindings] = PackageIntervalDueExpression::build(
            $query->getModel()->getConnection(),
            anchorColumn: $latestRunAtSql,
        );

        return static::whereHasPackageInterval($query)
            ->whereRaw("{$latestRunAtSql} is not null")
            ->whereRaw($dueSql, $bindings);
    }

    private static function applyAwaitingHeartbeatFreshnessFilter(Builder $query): Builder
    {
        $latestRunAtSql = static::latestScheduledRunAtSql($query);

        if ($latestRunAtSql === null) {
            return static::whereHasPackageInterval($query);
        }

        return static::whereHasPackageInterval($query)
            ->whereRaw("{$latestRunAtSql} is null");
    }

    private static function applyFreshFreshnessFilter(Builder $query): Builder
    {
        $latestRunAtSql = static::latestScheduledRunAtSql($query);

        if ($latestRunAtSql === null) {
            return static::whereHasPackageInterval($query);
        }

        [$dueSql, $bindings] = PackageIntervalDueExpression::build(
            $query->getModel()->getConnection(),
            anchorColumn: $latestRunAtSql,
        );

        return static::whereHasPackageInterval($query)
            ->whereRaw("{$latestRunAtSql} is not null")
            ->whereRaw("not ({$dueSql})", $bindings);
    }

    private static function whereHasPackageInterval(Builder $query): Builder
    {
        return $query
            ->whereNotNull('package_interval')
            ->where('package_interval', '!=', '');
    }

    private static function latestScheduledRunAtSql(Builder $query): ?string
    {
        return match ($query->getModel()->getTable()) {
            'monitor_apis' => '(select max(monitor_api_results.created_at) from monitor_api_results where monitor_api_results.monitor_api_id = monitor_apis.id and '.static::scheduledRunPredicate($query, 'monitor_api_results.is_on_demand').')',
            'websites' => '(select max(website_log_history.created_at) from website_log_history where website_log_history.website_id = websites.id and '.static::scheduledRunPredicate($query, 'website_log_history.is_on_demand').')',
            default => null,
        };
    }

    private static function scheduledRunPredicate(Builder $query, string $column): string
    {
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'pgsql' => "({$column} is null or {$column} = false)",
            default => "({$column} is null or {$column} = 0)",
        };
    }

    private static function attributeValue(object $record, string $attribute, mixed $default = null): mixed
    {
        if (method_exists($record, 'getAttribute')) {
            return $record->getAttribute($attribute) ?? $default;
        }

        return $record->{$attribute} ?? $default;
    }

    private static function latestRunAt(object $record): ?CarbonInterface
    {
        $latestResult = static::attributeValue($record, 'latestScheduledResult')
            ?? static::attributeValue($record, 'latestResult')
            ?? static::attributeValue($record, 'latestScheduledLogHistory')
            ?? static::attributeValue($record, 'latestLogHistory');

        return $latestResult?->created_at;
    }
}
