<?php

namespace App\Support;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Carbon;

class ScheduledFailureStreak
{
    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    public static function forApi(MonitorApis $monitor): array
    {
        $rows = MonitorApiResult::query()
            ->where('monitor_api_id', $monitor->id)
            ->where('is_on_demand', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'status', 'is_success', 'created_at']);

        return self::fromRows($rows, fn (MonitorApiResult $result): bool => self::apiResultFailed($result));
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    public static function forWebsite(Website $website): array
    {
        $rows = WebsiteLogHistory::query()
            ->where('website_id', $website->id)
            ->where('is_on_demand', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'status', 'http_status_code', 'created_at']);

        return self::fromRows($rows, fn (WebsiteLogHistory $result): bool => self::websiteResultFailed($result));
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    public static function forApiResult(MonitorApiResult $result): array
    {
        $monitor = $result->relationLoaded('monitorApi') ? $result->monitorApi : $result->monitorApi()->first();

        if (! $monitor instanceof MonitorApis || $result->is_on_demand || ! self::apiResultFailed($result)) {
            return self::empty();
        }

        return self::forApi($monitor);
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    public static function forWebsiteResult(WebsiteLogHistory $result): array
    {
        $website = $result->relationLoaded('website') ? $result->website : $result->website()->first();

        if (! $website instanceof Website || $result->is_on_demand || ! self::websiteResultFailed($result)) {
            return self::empty();
        }

        return self::forWebsite($website);
    }

    /**
     * @return array{count: int, first_failed_at: ?string}
     */
    public static function apiPayload(MonitorApis $monitor): array
    {
        return self::payload(self::forApi($monitor));
    }

    /**
     * @return array{count: int, first_failed_at: ?string}
     */
    public static function websitePayload(Website $website): array
    {
        return self::payload(self::forWebsite($website));
    }

    public static function labelForApi(MonitorApis $monitor): ?string
    {
        return self::label(self::forApi($monitor));
    }

    public static function labelForWebsite(Website $website): ?string
    {
        return self::label(self::forWebsite($website));
    }

    public static function descriptionForApi(MonitorApis $monitor): ?string
    {
        return self::description(self::forApi($monitor));
    }

    public static function descriptionForWebsite(Website $website): ?string
    {
        return self::description(self::forWebsite($website));
    }

    /**
     * @param  iterable<object>  $rows
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    private static function fromRows(iterable $rows, callable $failed): array
    {
        $count = 0;
        $firstFailedAt = null;

        foreach ($rows as $row) {
            if (! $failed($row)) {
                break;
            }

            $count++;
            $firstFailedAt = $row->created_at;
        }

        return [
            'count' => $count,
            'first_failed_at' => $firstFailedAt,
        ];
    }

    /**
     * @param  array{count: int, first_failed_at: ?Carbon}  $streak
     * @return array{count: int, first_failed_at: ?string}
     */
    private static function payload(array $streak): array
    {
        return [
            'count' => $streak['count'],
            'first_failed_at' => $streak['first_failed_at']?->toISOString(),
        ];
    }

    /**
     * @param  array{count: int, first_failed_at: ?Carbon}  $streak
     */
    private static function label(array $streak): ?string
    {
        if ($streak['count'] === 0) {
            return null;
        }

        return $streak['count'].' scheduled '.str('failure')->plural($streak['count']);
    }

    /**
     * @param  array{count: int, first_failed_at: ?Carbon}  $streak
     */
    private static function description(array $streak): ?string
    {
        if ($streak['count'] === 0 || ! $streak['first_failed_at'] instanceof Carbon) {
            return null;
        }

        return 'First failed '.$streak['first_failed_at']->diffForHumans();
    }

    /**
     * @return array{count: int, first_failed_at: null}
     */
    private static function empty(): array
    {
        return [
            'count' => 0,
            'first_failed_at' => null,
        ];
    }

    private static function apiResultFailed(MonitorApiResult $result): bool
    {
        return in_array($result->status, ['warning', 'danger'], true) || $result->is_success === false;
    }

    private static function websiteResultFailed(WebsiteLogHistory $result): bool
    {
        if (in_array($result->status, ['warning', 'danger'], true)) {
            return true;
        }

        $httpStatus = (int) ($result->http_status_code ?? 200);

        return $httpStatus === 0 || $httpStatus >= 400;
    }
}
