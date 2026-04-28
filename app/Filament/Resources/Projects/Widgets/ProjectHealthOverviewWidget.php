<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use App\Support\PackageCheckTableEvidence;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Header strip on the project view that summarises how many of this
 * application's tracked surfaces (active components, actively-monitored
 * websites, enabled monitor APIs) are failing, healthy, stale or still
 * waiting on data.
 *
 * Paused websites (uptime_check = false), disabled monitor APIs
 * (is_enabled = false) and archived components are excluded so the
 * counts only reflect surfaces the user has actually opted in to.
 *
 * "Failing" means current_status of warning/danger but excludes stale
 * rows; stale is reported separately and "no data" rolls up everything
 * that has not reported yet so users can spot misconfigured checks
 * immediately.
 */
class ProjectHealthOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    public ?Project $record = null;

    private const EMPTY_BUCKETS = [
        'failing' => 0,
        'healthy' => 0,
        'stale' => 0,
        'no_data' => 0,
    ];

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

        return [
            Stat::make('Failing', $failing)
                ->description($this->buildFailingDescription($counts))
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
    public function collectCounts(): array
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
            ->where('uptime_check', true)
            ->get(['current_status', 'last_heartbeat_at', 'package_interval', 'stale_at']);

        $apis = MonitorApis::query()
            ->where('project_id', $project->getKey())
            ->where('is_enabled', true)
            ->get(['current_status', 'last_heartbeat_at', 'package_interval', 'stale_at']);

        $componentBuckets = $components->reduce(function (array $carry, ProjectComponent $component): array {
            $carry[$this->classifyComponent($component)]++;

            return $carry;
        }, self::EMPTY_BUCKETS);

        $websiteBuckets = $this->bucketPackageChecks($websites);
        $apiBuckets = $this->bucketPackageChecks($apis);

        return [
            'tracked' => $components->count() + $websites->count() + $apis->count(),
            'failing' => $componentBuckets['failing'] + $websiteBuckets['failing'] + $apiBuckets['failing'],
            'healthy' => $componentBuckets['healthy'] + $websiteBuckets['healthy'] + $apiBuckets['healthy'],
            'stale' => $componentBuckets['stale'] + $websiteBuckets['stale'] + $apiBuckets['stale'],
            'no_data' => $componentBuckets['no_data'] + $websiteBuckets['no_data'] + $apiBuckets['no_data'],
            'failing_components' => $componentBuckets['failing'],
            'failing_websites' => $websiteBuckets['failing'],
            'failing_apis' => $apiBuckets['failing'],
        ];
    }

    private function classifyComponent(ProjectComponent $component): string
    {
        return match (true) {
            (bool) $component->is_stale => 'stale',
            $component->current_status === 'healthy' => 'healthy',
            in_array($component->current_status, ['warning', 'danger'], true) => 'failing',
            default => 'no_data',
        };
    }

    /**
     * Bucket Website / MonitorApis records by the same freshness rules shown
     * in package-managed tables. There, stale_at is the stale detection time,
     * not a future freshness threshold, so any populated stale_at keeps the
     * row stale until a later heartbeat clears it.
     *
     * @param  Collection<int, Website|MonitorApis>  $items
     * @return array{failing: int, healthy: int, stale: int, no_data: int}
     */
    private function bucketPackageChecks(Collection $items): array
    {
        return $items->reduce(function (array $carry, $item): array {
            $isStale = PackageCheckTableEvidence::freshnessState($item) === PackageCheckTableEvidence::STATE_STALE;

            $bucket = match (true) {
                $isStale => 'stale',
                $item->current_status === 'healthy' => 'healthy',
                in_array($item->current_status, ['warning', 'danger'], true) => 'failing',
                default => 'no_data',
            };
            $carry[$bucket]++;

            return $carry;
        }, self::EMPTY_BUCKETS);
    }

    /**
     * @param  array{failing: int, failing_components: int, failing_websites: int, failing_apis: int}  $counts
     */
    private function buildFailingDescription(array $counts): string
    {
        if ($counts['failing'] === 0) {
            return 'No warning or danger surfaces';
        }

        return collect([
            $counts['failing_components'] > 0 ? "{$counts['failing_components']} components" : null,
            $counts['failing_websites'] > 0 ? "{$counts['failing_websites']} websites" : null,
            $counts['failing_apis'] > 0 ? "{$counts['failing_apis']} APIs" : null,
        ])->filter()->implode(', ');
    }

    private function buildStaleDescription(int $stale, int $noData): string
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
