<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Header strip on the project view that summarises how many of this
 * application's tracked surfaces (active components, actively-monitored
 * websites, enabled monitor APIs) are failing, healthy, or pending.
 *
 * Websites with uptime or SSL checks enabled are included. Fully paused
 * websites, disabled monitor APIs (is_enabled = false) and archived
 * components are excluded so the counts only reflect surfaces the user has
 * actually opted in to.
 *
 * "Failing" means current_status of warning or danger. Pending rolls up
 * everything that has not produced a live check result yet.
 */
class ProjectHealthOverviewWidget extends BaseWidget
{
    /**
     * @var array<string>
     */
    public array $discoveredSchemaNames = [];

    public bool $areSchemaStateUpdateHooksDisabledForTesting = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    public ?Project $record = null;

    private const EMPTY_BUCKETS = [
        'failing' => 0,
        'healthy' => 0,
        'pending' => 0,
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
        $pending = $counts['pending'];

        return [
            Stat::make('Failing', $failing)
                ->description($this->buildFailingDescription($counts))
                ->descriptionIcon($failing === 0 ? 'heroicon-m-shield-check' : 'heroicon-m-exclamation-triangle')
                ->color($failing === 0 ? 'success' : 'danger'),

            Stat::make('Healthy', $healthy)
                ->description("of {$tracked} tracked surface".($tracked === 1 ? '' : 's'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($healthy > 0 ? 'success' : 'gray'),

            Stat::make('Pending', $pending)
                ->description($pending === 0 ? 'All tracked surfaces have live status' : "{$pending} awaiting first result")
                ->descriptionIcon('heroicon-m-clock')
                ->color($pending === 0 ? 'success' : 'warning'),

            Stat::make('Unmapped', $counts['unmapped'])
                ->description($this->buildUnmappedDescription($counts))
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color($counts['unmapped'] === 0 ? 'success' : 'warning'),
        ];
    }

    /**
     * @return array{
     *     tracked: int,
     *     failing: int,
     *     healthy: int,
     *     pending: int,
     *     failing_components: int,
     *     failing_websites: int,
     *     failing_apis: int,
     *     unmapped: int,
     *     unmapped_websites: int,
     *     unmapped_apis: int,
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
                'pending' => 0,
                'failing_components' => 0,
                'failing_websites' => 0,
                'failing_apis' => 0,
                'unmapped' => 0,
                'unmapped_websites' => 0,
                'unmapped_apis' => 0,
            ];
        }

        $components = ProjectComponent::query()
            ->where('project_id', $project->getKey())
            ->where('is_archived', false)
            ->with(['activeMonitorApis', 'activeWebsites'])
            ->get(['id', 'current_status', 'is_archived', 'source']);

        $websites = Website::query()
            ->where('project_id', $project->getKey())
            ->where(function ($query): void {
                $query
                    ->where('uptime_check', true)
                    ->orWhere('ssl_check', true);
            })
            ->get(['current_status', 'project_component_id']);

        $apis = MonitorApis::query()
            ->where('project_id', $project->getKey())
            ->where('is_enabled', true)
            ->get(['current_status', 'project_component_id']);

        $componentBuckets = $components->reduce(function (array $carry, ProjectComponent $component): array {
            $carry[$this->classifyComponent($component)]++;

            return $carry;
        }, self::EMPTY_BUCKETS);

        $websiteBuckets = $this->bucketPackageChecks($websites);
        $apiBuckets = $this->bucketPackageChecks($apis);
        $unmappedWebsites = $websites->whereNull('project_component_id')->count();
        $unmappedApis = $apis->whereNull('project_component_id')->count();

        return [
            'tracked' => $components->count() + $websites->count() + $apis->count(),
            'failing' => $componentBuckets['failing'] + $websiteBuckets['failing'] + $apiBuckets['failing'],
            'healthy' => $componentBuckets['healthy'] + $websiteBuckets['healthy'] + $apiBuckets['healthy'],
            'pending' => $componentBuckets['pending'] + $websiteBuckets['pending'] + $apiBuckets['pending'],
            'failing_components' => $componentBuckets['failing'],
            'failing_websites' => $websiteBuckets['failing'],
            'failing_apis' => $apiBuckets['failing'],
            'unmapped' => $unmappedWebsites + $unmappedApis,
            'unmapped_websites' => $unmappedWebsites,
            'unmapped_apis' => $unmappedApis,
        ];
    }

    private function classifyComponent(ProjectComponent $component): string
    {
        return match ($component->derivedCurrentStatus()) {
            'healthy' => 'healthy',
            'warning', 'danger' => 'failing',
            default => 'pending',
        };
    }

    /**
     * @param  Collection<int, Website|MonitorApis>  $items
     * @return array{failing: int, healthy: int, pending: int}
     */
    private function bucketPackageChecks(Collection $items): array
    {
        return $items->reduce(function (array $carry, $item): array {
            $bucket = match (true) {
                $item->current_status === 'healthy' => 'healthy',
                in_array($item->current_status, ['warning', 'danger'], true) => 'failing',
                default => 'pending',
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
            return 'No warning or failing surfaces';
        }

        return collect([
            $counts['failing_components'] > 0 ? "{$counts['failing_components']} components" : null,
            $counts['failing_websites'] > 0 ? "{$counts['failing_websites']} websites" : null,
            $counts['failing_apis'] > 0 ? "{$counts['failing_apis']} APIs" : null,
        ])->filter()->implode(', ');
    }

    /**
     * @param  array{unmapped: int, unmapped_websites: int, unmapped_apis: int}  $counts
     */
    private function buildUnmappedDescription(array $counts): string
    {
        if ($counts['unmapped'] === 0) {
            return 'All checks are mapped to components';
        }

        return collect([
            $counts['unmapped_websites'] > 0 ? $counts['unmapped_websites'].' '.str('website')->plural($counts['unmapped_websites']) : null,
            $counts['unmapped_apis'] > 0 ? $counts['unmapped_apis'].' '.str('API')->plural($counts['unmapped_apis']) : null,
        ])->filter()->implode(', ');
    }
}
