<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header strip on the project view that summarises how many of this
 * application's tracked surfaces (components, websites, monitor APIs)
 * are failing, healthy, stale or still waiting on data.
 *
 * The numbers should always add up to the total tracked surfaces, so
 * "failing" here means current_status of warning/danger but excludes
 * stale rows; stale is reported separately and "no data" rolls up
 * everything that has not reported yet so users can spot misconfigured
 * checks immediately.
 */
class ProjectHealthOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    public ?Project $record = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $counts = $this->collectCounts();
        $tracked = $counts['tracked'];

        if ($tracked === 0) {
            return [
                Stat::make('Tracked surfaces', 0)
                    ->description('No websites, APIs or components tracked yet')
                    ->descriptionIcon('heroicon-m-cube')
                    ->color('gray'),
            ];
        }

        $failing = $counts['failing'];
        $healthy = $counts['healthy'];
        $stale = $counts['stale'];
        $noData = $counts['no_data'];

        $failingDescription = collect([
            $counts['failing_components'] > 0 ? "{$counts['failing_components']} components" : null,
            $counts['failing_websites'] > 0 ? "{$counts['failing_websites']} websites" : null,
            $counts['failing_apis'] > 0 ? "{$counts['failing_apis']} APIs" : null,
        ])->filter()->implode(', ');

        return [
            Stat::make('Failing', $failing)
                ->description($failing === 0
                    ? 'No warning or danger surfaces'
                    : ($failingDescription !== '' ? $failingDescription : 'Warning or danger right now'))
                ->descriptionIcon($failing === 0 ? 'heroicon-m-shield-check' : 'heroicon-m-exclamation-triangle')
                ->color($failing === 0 ? 'success' : 'danger'),

            Stat::make('Healthy', $healthy)
                ->description("of {$tracked} tracked surface".($tracked === 1 ? '' : 's'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($healthy > 0 ? 'success' : 'gray'),

            Stat::make('Stale / No data', $stale + $noData)
                ->description($this->buildStaleDescription($stale, $noData))
                ->descriptionIcon('heroicon-m-clock')
                ->color(($stale + $noData) === 0 ? 'success' : 'warning'),
        ];
    }

    /**
     * @return array{
     *     tracked: int,
     *     failing: int,
     *     healthy: int,
     *     stale: int,
     *     no_data: int,
     *     failing_components: int,
     *     failing_websites: int,
     *     failing_apis: int,
     * }
     */
    protected function collectCounts(): array
    {
        $project = $this->record;

        if ($project === null) {
            return [
                'tracked' => 0,
                'failing' => 0,
                'healthy' => 0,
                'stale' => 0,
                'no_data' => 0,
                'failing_components' => 0,
                'failing_websites' => 0,
                'failing_apis' => 0,
            ];
        }

        $components = ProjectComponent::query()
            ->where('project_id', $project->getKey())
            ->where('is_archived', false)
            ->get(['current_status', 'is_stale']);

        $websites = Website::query()
            ->where('project_id', $project->getKey())
            ->get(['current_status', 'stale_at']);

        $apis = MonitorApis::query()
            ->where('project_id', $project->getKey())
            ->get(['current_status', 'stale_at']);

        $now = now();

        $componentBuckets = $components->reduce(function (array $carry, ProjectComponent $component) {
            $bucket = match (true) {
                (bool) $component->is_stale => 'stale',
                $component->current_status === 'healthy' => 'healthy',
                in_array($component->current_status, ['warning', 'danger'], true) => 'failing',
                default => 'no_data',
            };
            $carry[$bucket]++;

            return $carry;
        }, ['failing' => 0, 'healthy' => 0, 'stale' => 0, 'no_data' => 0]);

        $websiteBuckets = $websites->reduce(function (array $carry, Website $website) use ($now) {
            $isStale = $website->stale_at !== null && $website->stale_at->lessThan($now);
            $bucket = match (true) {
                $isStale => 'stale',
                $website->current_status === 'healthy' => 'healthy',
                in_array($website->current_status, ['warning', 'danger'], true) => 'failing',
                default => 'no_data',
            };
            $carry[$bucket]++;

            return $carry;
        }, ['failing' => 0, 'healthy' => 0, 'stale' => 0, 'no_data' => 0]);

        $apiBuckets = $apis->reduce(function (array $carry, MonitorApis $api) use ($now) {
            $isStale = $api->stale_at !== null && $api->stale_at->lessThan($now);
            $bucket = match (true) {
                $isStale => 'stale',
                $api->current_status === 'healthy' => 'healthy',
                in_array($api->current_status, ['warning', 'danger'], true) => 'failing',
                default => 'no_data',
            };
            $carry[$bucket]++;

            return $carry;
        }, ['failing' => 0, 'healthy' => 0, 'stale' => 0, 'no_data' => 0]);

        $tracked = $components->count() + $websites->count() + $apis->count();

        return [
            'tracked' => $tracked,
            'failing' => $componentBuckets['failing'] + $websiteBuckets['failing'] + $apiBuckets['failing'],
            'healthy' => $componentBuckets['healthy'] + $websiteBuckets['healthy'] + $apiBuckets['healthy'],
            'stale' => $componentBuckets['stale'] + $websiteBuckets['stale'] + $apiBuckets['stale'],
            'no_data' => $componentBuckets['no_data'] + $websiteBuckets['no_data'] + $apiBuckets['no_data'],
            'failing_components' => $componentBuckets['failing'],
            'failing_websites' => $websiteBuckets['failing'],
            'failing_apis' => $apiBuckets['failing'],
        ];
    }

    protected function buildStaleDescription(int $stale, int $noData): string
    {
        if ($stale === 0 && $noData === 0) {
            return 'Heartbeats are fresh';
        }

        $parts = [];

        if ($stale > 0) {
            $parts[] = "{$stale} stale";
        }

        if ($noData > 0) {
            $parts[] = "{$noData} awaiting first heartbeat";
        }

        return implode(' • ', $parts);
    }
}
