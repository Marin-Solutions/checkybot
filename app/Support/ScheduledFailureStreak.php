<?php

namespace App\Support;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ScheduledFailureStreak
{
    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    public static function forApi(MonitorApis $monitor): array
    {
        return self::apiStreakFromQuery(self::apiScheduledRows($monitor));
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    public static function forWebsite(Website $website): array
    {
        return self::websiteStreakFromQuery(self::websiteScheduledRows($website));
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

        return self::apiStreakFromQuery(self::limitToResult(self::apiScheduledRows($monitor), $result));
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

        return self::websiteStreakFromQuery(self::limitToResult(self::websiteScheduledRows($website), $result));
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

    public static function displayForApi(MonitorApis $monitor): ?string
    {
        return self::display(self::forApi($monitor));
    }

    public static function displayForWebsite(Website $website): ?string
    {
        return self::display(self::forWebsite($website));
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

    private static function apiScheduledRows(MonitorApis $monitor): Builder
    {
        return MonitorApiResult::query()
            ->where('monitor_api_id', $monitor->id)
            ->where('is_on_demand', false);
    }

    private static function websiteScheduledRows(Website $website): Builder
    {
        return WebsiteLogHistory::query()
            ->where('website_id', $website->id)
            ->where('is_on_demand', false);
    }

    private static function limitToResult(Builder $query, MonitorApiResult|WebsiteLogHistory $result): Builder
    {
        return $query->where(function (Builder $query) use ($result): void {
            $query
                ->where('created_at', '<', $result->created_at)
                ->orWhere(function (Builder $query) use ($result): void {
                    $query
                        ->where('created_at', $result->created_at)
                        ->where('id', '<=', $result->id);
                });
        });
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    private static function apiStreakFromQuery(Builder $query): array
    {
        $boundary = self::latestBoundary(
            $query,
            fn (Builder $query): Builder => self::whereApiNonFailure($query),
        );

        return self::aggregateAfterBoundary($query, $boundary);
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    private static function websiteStreakFromQuery(Builder $query): array
    {
        $boundary = self::latestBoundary(
            $query,
            fn (Builder $query): Builder => self::whereWebsiteNonFailure($query),
        );

        return self::aggregateAfterBoundary($query, $boundary);
    }

    private static function latestBoundary(Builder $query, callable $nonFailure): MonitorApiResult|WebsiteLogHistory|null
    {
        return $nonFailure(clone $query)
            ->select(['id', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{count: int, first_failed_at: ?Carbon}
     */
    private static function aggregateAfterBoundary(Builder $query, MonitorApiResult|WebsiteLogHistory|null $boundary): array
    {
        if ($boundary instanceof MonitorApiResult || $boundary instanceof WebsiteLogHistory) {
            $query->where(function (Builder $query) use ($boundary): void {
                $query
                    ->where('created_at', '>', $boundary->created_at)
                    ->orWhere(function (Builder $query) use ($boundary): void {
                        $query
                            ->where('created_at', $boundary->created_at)
                            ->where('id', '>', $boundary->id);
                    });
            });
        }

        $aggregate = $query
            ->selectRaw('COUNT(*) as streak_count')
            ->selectRaw('MIN(created_at) as first_failed_at')
            ->first();

        return [
            'count' => (int) ($aggregate?->streak_count ?? 0),
            'first_failed_at' => filled($aggregate?->first_failed_at)
                ? Carbon::parse($aggregate->first_failed_at)
                : null,
        ];
    }

    private static function whereApiNonFailure(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', ['warning', 'danger']);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('is_success')
                    ->orWhere('is_success', '!=', false);
            });
    }

    private static function whereWebsiteNonFailure(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', ['warning', 'danger']);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('http_status_code')
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('http_status_code', '!=', 0)
                            ->where('http_status_code', '<', 400);
                    });
            });
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
     * @param  array{count: int, first_failed_at: ?Carbon}  $streak
     */
    private static function display(array $streak): ?string
    {
        $label = self::label($streak);
        $description = self::description($streak);

        if ($label === null) {
            return null;
        }

        return $description === null ? $label : "{$label} · {$description}";
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
