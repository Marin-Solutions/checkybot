<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MonitorApisResource;
use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use App\Filament\Resources\WebsiteResource;
use App\Models\Incident;
use App\Models\MonitorApiResult;
use App\Models\ProjectComponentHeartbeat;
use App\Models\WebsiteLogHistory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IncidentFeedWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent incidents';

    protected static ?string $description = 'Warning and danger transitions from websites, API monitors and components — in the order they happened.';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected const INCIDENT_STATUSES = ['warning', 'danger'];

    protected const NON_INCIDENT_STATUSES = ['healthy', 'unknown'];

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildIncidentsQuery())
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->sinceInUserZone()
                    ->tooltip(fn (Incident $record): string => $record->occurred_at?->toDayDateTimeString() ?? '')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'danger' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'website' => 'Website',
                        'api' => 'API monitor',
                        'component' => 'Component',
                        default => ucfirst($state),
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'website' => 'heroicon-o-globe-alt',
                        'api' => 'heroicon-o-bolt',
                        'component' => 'heroicon-o-cube',
                        default => 'heroicon-o-question-mark-circle',
                    }),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->weight('bold')
                    ->url(fn (Incident $record): ?string => $this->resolveTargetUrl($record), shouldOpenInNewTab: false)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('subject', 'like', "%{$search}%"))
                    ->wrap(),
                TextColumn::make('summary')
                    ->label('Evidence')
                    ->limit(90)
                    ->tooltip(fn (Incident $record): ?string => $record->summary)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Severity')
                    ->options([
                        'danger' => 'Danger',
                        'warning' => 'Warning',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->where('status', $data['value']);
                    })
                    ->placeholder('All severities'),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'website' => 'Websites',
                        'api' => 'API monitors',
                        'component' => 'Components',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->where('source', $data['value']);
                    })
                    ->placeholder('All sources'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('All clear')
            ->emptyStateDescription($this->getEmptyStateDescriptionText())
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    /**
     * Returns the project id the feed is scoped to, or null for the global feed.
     *
     * Subclasses must derive this from a Livewire-tracked public property
     * (mount() runs only on initial render, so reading from a protected
     * field set in mount() is unsafe across polling/sort/filter requests).
     */
    protected function getScopedProjectId(): ?int
    {
        return null;
    }

    protected function getEmptyStateDescriptionText(): string
    {
        return 'No warning or danger transitions from your websites, API monitors or components in the selected window.';
    }

    protected function buildIncidentsQuery(): Builder
    {
        return self::buildIncidentsQueryFor(
            userId: (int) Auth::id(),
            since: now()->subDays(7),
            projectId: $this->getScopedProjectId(),
        );
    }

    /**
     * Build the union query that powers every incident feed in the app.
     *
     * Pass a $projectId to restrict the feed to a single application's
     * websites, monitor APIs and components.
     */
    public static function buildIncidentsQueryFor(int $userId, \Carbon\CarbonInterface $since, ?int $projectId = null): Builder
    {
        $websiteRuns = WebsiteLogHistory::query()
            ->join('websites', 'websites.id', '=', 'website_log_history.website_id')
            ->whereNull('websites.deleted_at')
            ->where('website_log_history.is_on_demand', false)
            ->where('websites.created_by', $userId)
            ->when($projectId !== null, fn (Builder $query): Builder => $query->where('websites.project_id', $projectId))
            ->selectRaw("CONCAT('website_log-', website_log_history.id) as id")
            ->selectRaw('website_log_history.id as source_row_id')
            ->selectRaw("'website' as source")
            ->selectRaw('website_log_history.website_id as source_subject_id')
            ->selectRaw("COALESCE(NULLIF(website_log_history.status, ''), 'unknown') as normalized_status")
            ->selectRaw('websites.name as subject')
            ->selectRaw('website_log_history.website_id as subject_id')
            ->selectRaw("COALESCE(NULLIF(website_log_history.summary, ''), CONCAT('HTTP ', COALESCE(website_log_history.http_status_code, 0))) as summary")
            ->selectRaw('website_log_history.created_at as occurred_at');

        // API results use a stricter severity definition than websites or components:
        // older rows written before the `status` column existed still need to surface
        // as incidents, so we also pick up any failed result (`is_success = false`)
        // even when `status` is null/empty. The normalized status expression below
        // preserves that behavior while still letting the feed compare each row
        // against its previous scheduled status.
        $apiRuns = MonitorApiResult::query()
            ->join('monitor_apis', 'monitor_apis.id', '=', 'monitor_api_results.monitor_api_id')
            ->where('monitor_apis.created_by', $userId)
            ->whereNull('monitor_apis.deleted_at')
            ->where('monitor_api_results.is_on_demand', false)
            ->when($projectId !== null, fn (Builder $query): Builder => $query->where('monitor_apis.project_id', $projectId))
            ->selectRaw("CONCAT('api_result-', monitor_api_results.id) as id")
            ->selectRaw('monitor_api_results.id as source_row_id')
            ->selectRaw("'api' as source")
            ->selectRaw('monitor_api_results.monitor_api_id as source_subject_id')
            ->selectRaw("
                CASE
                    WHEN NULLIF(monitor_api_results.status, '') IS NOT NULL THEN monitor_api_results.status
                    WHEN monitor_api_results.is_success = 0 THEN 'danger'
                    ELSE 'healthy'
                END as normalized_status
            ")
            ->selectRaw('monitor_apis.title as subject')
            ->selectRaw('monitor_api_results.monitor_api_id as subject_id')
            ->selectRaw("COALESCE(NULLIF(monitor_api_results.summary, ''), CONCAT('HTTP ', COALESCE(monitor_api_results.http_code, 0))) as summary")
            ->selectRaw('monitor_api_results.created_at as occurred_at');

        $componentRuns = ProjectComponentHeartbeat::query()
            ->join('project_components', 'project_components.id', '=', 'project_component_heartbeats.project_component_id')
            ->where('project_components.created_by', $userId)
            ->when($projectId !== null, fn (Builder $query): Builder => $query->where('project_components.project_id', $projectId))
            ->selectRaw("CONCAT('component_heartbeat-', project_component_heartbeats.id) as id")
            ->selectRaw('project_component_heartbeats.id as source_row_id')
            ->selectRaw("'component' as source")
            ->selectRaw('project_component_heartbeats.project_component_id as source_subject_id')
            ->selectRaw("COALESCE(NULLIF(project_component_heartbeats.status, ''), 'unknown') as normalized_status")
            ->selectRaw('project_component_heartbeats.component_name as subject')
            ->selectRaw('project_component_heartbeats.project_component_id as subject_id')
            ->selectRaw("COALESCE(NULLIF(project_component_heartbeats.summary, ''), project_component_heartbeats.event) as summary")
            ->selectRaw('project_component_heartbeats.observed_at as occurred_at');

        $runs = $websiteRuns
            ->toBase()
            ->unionAll($apiRuns->toBase())
            ->unionAll($componentRuns->toBase());

        $rankedRuns = DB::query()
            ->fromSub($runs, 'runs')
            ->selectRaw('id')
            ->selectRaw('source')
            ->selectRaw('normalized_status as status')
            ->selectRaw('subject')
            ->selectRaw('subject_id')
            ->selectRaw('summary')
            ->selectRaw('occurred_at')
            ->selectRaw('
                LAG(normalized_status) OVER (
                    PARTITION BY source, source_subject_id
                    ORDER BY occurred_at, source_row_id
                ) as previous_status
            ');

        return Incident::query()
            ->fromSub($rankedRuns, 'incidents')
            ->whereIn('status', self::INCIDENT_STATUSES)
            ->where('occurred_at', '>=', $since)
            ->where(function (Builder $query): void {
                $query->whereNull('previous_status')
                    ->orWhereIn('previous_status', self::NON_INCIDENT_STATUSES)
                    ->orWhereColumn('previous_status', '!=', 'status');
            });
    }

    protected function resolveTargetUrl(Incident $record): ?string
    {
        return match ($record->source) {
            'website' => WebsiteResource::getUrl('view', ['record' => $record->subject_id]),
            'api' => MonitorApisResource::getUrl('view', ['record' => $record->subject_id]),
            'component' => ProjectComponentResource::getUrl('view', ['record' => $record->subject_id]),
            default => null,
        };
    }
}
