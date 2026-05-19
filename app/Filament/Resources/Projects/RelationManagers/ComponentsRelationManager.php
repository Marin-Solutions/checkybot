<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\ProjectComponents\Tables\ProjectComponentsTable;
use App\Filament\Support\HealthStatusFilter;
use App\Models\ProjectComponent;
use App\Support\HealthStatusLabel;
use App\Support\ProjectComponentDeliveryState;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComponentsRelationManager extends RelationManager
{
    private const FAILING_CHILD_STATUSES = ['warning', 'danger'];

    protected static string $relationship = 'components';

    protected static ?string $title = 'Components';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'activeMonitorApis:id,project_component_id,current_status',
                'activeWebsites:id,project_component_id,current_status,uptime_check,ssl_check',
            ])->withCount([
                'activeMonitorApis as active_failing_monitor_apis_count' => fn (Builder $query): Builder => $query
                    ->whereIn('current_status', self::FAILING_CHILD_STATUSES),
                'activeWebsites as active_failing_websites_count' => fn (Builder $query): Builder => $query
                    ->whereIn('current_status', self::FAILING_CHILD_STATUSES),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('current_status')
                    ->state(fn (ProjectComponent $record): string => $record->derivedCurrentStatus())
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('active_failing_monitor_apis_count')
                    ->label('Failing APIs')
                    ->state(fn (ProjectComponent $record): int => $this->activeFailingMonitorApisCount($record))
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('active_failing_websites_count')
                    ->label('Failing Websites')
                    ->state(fn (ProjectComponent $record): int => $this->activeFailingWebsitesCount($record))
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('declared_interval')
                    ->label('Interval'),
                Tables\Columns\TextColumn::make('delivery_state')
                    ->label('Delivery State')
                    ->state(fn (ProjectComponent $record): string => ProjectComponentDeliveryState::label($record))
                    ->badge()
                    ->color(fn (string $state): string => ProjectComponentDeliveryState::color($state)),
                Tables\Columns\TextColumn::make('summary')
                    ->state(fn (ProjectComponent $record): string => $record->derivedStatusSummary())
                    ->wrap()
                    ->limit(80),
            ])
            ->filters([
                HealthStatusFilter::makeForNonNullableColumn(),
                Tables\Filters\SelectFilter::make('delivery_state')
                    ->label('Delivery State')
                    ->options(ProjectComponentDeliveryState::options())
                    ->query(fn (Builder $query, array $data): Builder => ProjectComponentDeliveryState::applyFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                HealthStatusFilter::onlyFailing(
                    activeScope: fn (Builder $query): Builder => $query->where('is_archived', false),
                ),
            ])
            ->recordActions(ProjectComponentsTable::recordActions(includeEdit: false))
            ->toolbarActions([
                BulkActionGroup::make(ProjectComponentsTable::bulkActions(includeDelete: false)),
            ])
            ->defaultSort('name');
    }

    private function activeFailingMonitorApisCount(ProjectComponent $record): int
    {
        if ($record->active_failing_monitor_apis_count !== null) {
            return (int) $record->active_failing_monitor_apis_count;
        }

        if ($record->relationLoaded('activeMonitorApis')) {
            return $record->activeMonitorApis
                ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
                ->count();
        }

        return $record->activeMonitorApis()
            ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
            ->count();
    }

    private function activeFailingWebsitesCount(ProjectComponent $record): int
    {
        if ($record->active_failing_websites_count !== null) {
            return (int) $record->active_failing_websites_count;
        }

        if ($record->relationLoaded('activeWebsites')) {
            return $record->activeWebsites
                ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
                ->count();
        }

        return $record->activeWebsites()
            ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
            ->count();
    }
}
