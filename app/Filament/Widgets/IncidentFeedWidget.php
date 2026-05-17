<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MonitorApisResource;
use App\Filament\Resources\WebsiteResource;
use App\Models\Incident;
use App\Models\MonitorApiResult;
use App\Models\WebsiteLogHistory;
use Filament\Actions\Action;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IncidentFeedWidget extends BaseWidget
{
    /**
     * @var array<string>
     */
    public array $discoveredSchemaNames = [];

    public bool $areSchemaStateUpdateHooksDisabledForTesting = false;

    protected static ?string $heading = 'Recent incidents';

    protected static ?string $description = 'Warning, danger and recovery transitions from websites and API monitors — in the order they happened.';

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
                    ->label('Transition')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'danger' => 'danger',
                        'warning' => 'warning',
                        'healthy' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'healthy', 'unknown' => 'RECOVERED',
                        default => strtoupper($state),
                    })
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Current state')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'danger' : 'success')
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Active' : 'Resolved')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'website' => 'Website',
                        'api' => 'API monitor',
                        default => ucfirst($state),
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'website' => 'heroicon-o-globe-alt',
                        'api' => 'heroicon-o-bolt',
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
                    ->label('Transition')
                    ->options([
                        'danger' => 'Failing',
                        'warning' => 'Warning',
                        'recovered' => 'Recovered',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        if ($data['value'] === 'recovered') {
                            return $query->whereIn('status', self::NON_INCIDENT_STATUSES);
                        }

                        return $query->where('status', $data['value']);
                    })
                    ->placeholder('All transitions'),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'website' => 'Websites',
                        'api' => 'API monitors',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->where('source', $data['value']);
                    })
                    ->placeholder('All sources'),
            ])
            ->recordActions([
                Action::make('viewEvidence')
                    ->label('View Evidence')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->modalHeading(fn (Incident $record): string => "{$this->formatSourceLabel($record->source)} evidence")
                    ->modalDescription(fn (Incident $record): string => "Exact supporting run for {$record->subject}.")
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn (Action $action): Action => $action
                        ->name('closeEvidenceModal')
                        ->label('Close'))
                    ->schema([
                        SchemaView::make('filament.widgets.incident-feed-evidence-modal')
                            ->viewData(fn (Incident $record): array => [
                                'incident' => $record,
                                'evidence' => $this->resolveEvidenceRecord($record),
                                'targetUrl' => $this->resolveTargetUrl($record),
                            ]),
                    ]),
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
        return 'No warning, danger or recovery transitions from your websites or API monitors in the selected window.';
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
     * websites and monitor APIs.
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
            ->selectRaw('
                CASE
                    WHEN websites.uptime_check OR websites.ssl_check THEN 1
                    ELSE 0
                END as current_monitoring_enabled
            ')
            ->selectRaw('websites.name as subject')
            ->selectRaw('website_log_history.website_id as subject_id')
            ->selectRaw("COALESCE(NULLIF(website_log_history.summary, ''), CONCAT('HTTP ', COALESCE(website_log_history.http_status_code, 0))) as summary")
            ->selectRaw('website_log_history.created_at as occurred_at');

        // API results use a stricter severity definition than websites:
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
            ->selectRaw('
                CASE
                    WHEN monitor_apis.is_enabled THEN 1
                    ELSE 0
                END as current_monitoring_enabled
            ')
            ->selectRaw('monitor_apis.title as subject')
            ->selectRaw('monitor_api_results.monitor_api_id as subject_id')
            ->selectRaw("COALESCE(NULLIF(monitor_api_results.summary, ''), CONCAT('HTTP ', COALESCE(monitor_api_results.http_code, 0))) as summary")
            ->selectRaw('monitor_api_results.created_at as occurred_at');

        $runs = self::limitRunsToFeedWindow($websiteRuns->toBase(), $since)
            ->unionAll(self::limitRunsToFeedWindow($apiRuns->toBase(), $since));

        $rankedRuns = DB::query()
            ->fromSub($runs, 'runs')
            ->selectRaw('id')
            ->selectRaw('source_row_id')
            ->selectRaw('source')
            ->selectRaw('normalized_status as status')
            ->selectRaw('subject')
            ->selectRaw('subject_id')
            ->selectRaw('summary')
            ->selectRaw('occurred_at')
            ->selectRaw('
                FIRST_VALUE(current_monitoring_enabled) OVER (
                    PARTITION BY source, source_subject_id
                    ORDER BY occurred_at DESC, source_row_id DESC
                    ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
                ) as current_monitoring_enabled
            ')
            ->selectRaw('
                FIRST_VALUE(normalized_status) OVER (
                    PARTITION BY source, source_subject_id
                    ORDER BY occurred_at DESC, source_row_id DESC
                    ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
                ) as current_status
            ')
            ->selectRaw('
                LAG(normalized_status) OVER (
                    PARTITION BY source, source_subject_id
                    ORDER BY occurred_at, source_row_id
                ) as previous_status
            ');

        return Incident::query()
            ->fromSub($rankedRuns, 'incidents')
            ->where('occurred_at', '>=', $since)
            ->where(function (Builder $transitionQuery): void {
                $transitionQuery
                    ->where(function (Builder $incidentTransitionQuery): void {
                        $incidentTransitionQuery
                            ->whereIn('status', self::INCIDENT_STATUSES)
                            ->where(function (Builder $statusChangeQuery): void {
                                $statusChangeQuery
                                    ->whereNull('previous_status')
                                    ->orWhereIn('previous_status', self::NON_INCIDENT_STATUSES)
                                    ->orWhereColumn('previous_status', '!=', 'status');
                            });
                    })
                    ->orWhere(function (Builder $recoveryTransitionQuery): void {
                        $recoveryTransitionQuery
                            ->whereIn('status', self::NON_INCIDENT_STATUSES)
                            ->whereIn('previous_status', self::INCIDENT_STATUSES);
                    });
            })
            ->select('incidents.*')
            ->selectRaw("
                CASE
                    WHEN current_monitoring_enabled = 1 AND current_status IN ('warning', 'danger') THEN 'active'
                    ELSE 'resolved'
                END as state
            ");
    }

    /**
     * Keep the full feed window plus each subject's latest pre-window row so
     * transition ranking has the prior state context without scanning all history.
     */
    protected static function limitRunsToFeedWindow(QueryBuilder $baseRuns, \Carbon\CarbonInterface $since): QueryBuilder
    {
        $windowRuns = DB::query()
            ->fromSub(clone $baseRuns, 'window_runs')
            ->select('id', 'source_row_id', 'source', 'source_subject_id', 'normalized_status', 'current_monitoring_enabled', 'subject', 'subject_id', 'summary', 'occurred_at')
            ->where('occurred_at', '>=', $since);

        $latestPriorRuns = DB::query()
            ->fromSub(
                DB::query()
                    ->fromSub(clone $baseRuns, 'prior_runs')
                    ->select('id', 'source_row_id', 'source', 'source_subject_id', 'normalized_status', 'current_monitoring_enabled', 'subject', 'subject_id', 'summary', 'occurred_at')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY source, source_subject_id ORDER BY occurred_at DESC, source_row_id DESC) as prior_rank')
                    ->where('occurred_at', '<', $since),
                'ranked_prior_runs'
            )
            ->select('id', 'source_row_id', 'source', 'source_subject_id', 'normalized_status', 'current_monitoring_enabled', 'subject', 'subject_id', 'summary', 'occurred_at')
            ->where('prior_rank', 1);

        return $windowRuns->unionAll($latestPriorRuns);
    }

    protected function resolveTargetUrl(Incident $record): ?string
    {
        return match ($record->source) {
            'website' => WebsiteResource::getUrl('view', ['record' => $record->subject_id]),
            'api' => MonitorApisResource::getUrl('view', ['record' => $record->subject_id]),
            default => null,
        };
    }

    protected function resolveEvidenceRecord(Incident $record): ?Model
    {
        $sourceRowId = (int) $record->source_row_id;
        $projectId = $this->getScopedProjectId();
        $userId = (int) Auth::id();

        return match ($record->source) {
            'website' => WebsiteLogHistory::query()
                ->with('website')
                ->whereKey($sourceRowId)
                ->whereHas('website', function (Builder $query) use ($projectId, $userId): void {
                    $query
                        ->where('created_by', $userId)
                        ->when($projectId !== null, fn (Builder $query): Builder => $query->where('project_id', $projectId));
                })
                ->first(),
            'api' => MonitorApiResult::query()
                ->with('monitorApi')
                ->whereKey($sourceRowId)
                ->whereHas('monitorApi', function (Builder $query) use ($projectId, $userId): void {
                    $query
                        ->where('created_by', $userId)
                        ->when($projectId !== null, fn (Builder $query): Builder => $query->where('project_id', $projectId));
                })
                ->first(),
            default => null,
        };
    }

    protected function formatSourceLabel(?string $source): string
    {
        return match ($source) {
            'website' => 'Website run',
            'api' => 'API result',
            default => 'Incident',
        };
    }
}
