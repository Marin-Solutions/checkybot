<?php

namespace App\Filament\Resources\Concerns;

/**
 * Adds a navigation badge that renders "unhealthy/total" in the `danger` color
 * whenever any record exposed by the resource has a `current_status` of
 * `warning` or `danger`. Falls back to a plain total (and the default badge
 * color) when everything is healthy.
 *
 * The counts are based on `static::getEloquentQuery()` so the badge always
 * reflects the exact same scope as the resource's list table.
 *
 * Both helpers share a single pair of queries via a container-bound cache so
 * they cannot query twice per render and cannot drift apart on what
 * "unhealthy" means. Using the container (rather than a raw static property)
 * keeps the cache bound to the current request in production and to the
 * current test's application instance under `RefreshDatabase`.
 */
trait HasUnhealthyNavigationBadge
{
    /**
     * Statuses considered unhealthy for the navigation badge.
     *
     * @return array<int, string>
     */
    protected static function unhealthyNavigationBadgeStatuses(): array
    {
        return ['warning', 'danger'];
    }

    public static function getNavigationBadge(): ?string
    {
        $counts = static::resolveUnhealthyNavigationBadgeCounts();

        if ($counts['unhealthy'] > 0) {
            return number_format($counts['unhealthy']).'/'.number_format($counts['total']);
        }

        return number_format($counts['total']);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::resolveUnhealthyNavigationBadgeCounts()['unhealthy'] > 0
            ? 'danger'
            : null;
    }

    /**
     * @return array{total: int, unhealthy: int}
     */
    protected static function resolveUnhealthyNavigationBadgeCounts(): array
    {
        $cacheKey = static::unhealthyNavigationBadgeCacheKey();

        if (app()->bound($cacheKey)) {
            return app($cacheKey);
        }

        $base = static::getEloquentQuery();

        $total = (clone $base)->toBase()->count();
        $unhealthy = (clone $base)
            ->whereIn('current_status', static::unhealthyNavigationBadgeStatuses())
            ->toBase()
            ->count();

        $counts = [
            'total' => (int) $total,
            'unhealthy' => (int) $unhealthy,
        ];

        app()->instance($cacheKey, $counts);

        return $counts;
    }

    protected static function unhealthyNavigationBadgeCacheKey(): string
    {
        return 'filament.navigation-badge.counts.'.static::class;
    }

    /**
     * Forget the cached counts. Useful when a long-running request mutates
     * records and still needs a fresh badge reading afterwards.
     */
    public static function flushUnhealthyNavigationBadgeCache(): void
    {
        $cacheKey = static::unhealthyNavigationBadgeCacheKey();

        if (app()->bound($cacheKey)) {
            app()->forgetInstance($cacheKey);
        }
    }
}
