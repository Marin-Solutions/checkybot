<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use App\Models\NotificationChannels;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    protected static ?string $modelLabel = 'Monitoring Rule';

    protected static ?string $title = 'Monitoring Rules';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('metric')
                    ->required()
                    ->label('Monitor')
                    ->options([
                        'cpu_usage' => 'CPU Usage',
                        'ram_usage' => 'RAM Usage',
                        'disk_usage' => 'Disk Usage',
                    ])
                    ->columnSpan(2),
                Forms\Components\Grid::make(3)
                    ->schema([
                        Forms\Components\Select::make('operator')
                            ->required()
                            ->options([
                                '>' => 'Above',
                                '<' => 'Below',
                                '=' => 'Equals',
                            ]),
                        Forms\Components\TextInput::make('value')
                            ->required()
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100),
                        Forms\Components\Select::make('channel')
                            ->required()
                            ->label('Notification Channel')
                            ->options(function () {
                                return NotificationChannels::where('created_by', auth()->id())
                                    ->pluck('title', 'id');
                            })
                            ->rules([
                                fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $owned = NotificationChannels::query()
                                        ->where('created_by', auth()->id())
                                        ->whereKey($value)
                                        ->exists();

                                    if (! $owned) {
                                        $fail('Select one of your webhook channels.');
                                    }
                                },
                            ]),
                    ]),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('metric')
            ->columns([
                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn ($record): string => $this->ruleState($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Triggered' => 'danger',
                        'Reporter stale' => 'warning',
                        'Metric unreadable' => 'warning',
                        'Recovered' => 'success',
                        'Inactive' => 'gray',
                        'Awaiting data' => 'gray',
                        default => 'info',
                    })
                    ->description(fn ($record): ?string => $this->stateDescription($record)),
                Tables\Columns\TextColumn::make('metric')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('operator')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '>' => 'Above',
                        '<' => 'Below',
                        '=' => 'Equals',
                    }),
                Tables\Columns\TextColumn::make('value')
                    ->label('Threshold')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('last_evaluated_value')
                    ->label('Last value')
                    ->suffix('%')
                    ->placeholder('Not evaluated')
                    ->description(fn ($record): ?string => $this->lastValueDescription($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(function ($state) {
                        $ownerId = $this->getOwnerRecord()?->created_by ?? auth()->id();

                        return NotificationChannels::query()
                            ->where('created_by', $ownerId)
                            ->find($state)?->title ?? $state;
                    }),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('triggered_at')
                    ->label('Triggered')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->sortable(),
                Tables\Columns\TextColumn::make('recovered_at')
                    ->label('Recovered')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Not recovered')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()->authorize(true),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()->authorize(true),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function ruleState($record): string
    {
        if (! $record->is_active) {
            return 'Inactive';
        }

        if ($record->is_triggered) {
            return 'Triggered';
        }

        if ($record->last_evaluation_status === 'skipped_stale_reporter') {
            return 'Reporter stale';
        }

        if ($record->last_evaluation_status === 'skipped_unreadable_metric') {
            return 'Metric unreadable';
        }

        if ($record->last_evaluation_status === 'skipped_missing_reporter') {
            return 'Awaiting data';
        }

        if ($record->recovered_at instanceof Carbon) {
            return 'Recovered';
        }

        return 'Monitoring';
    }

    private function stateDescription($record): ?string
    {
        if (in_array($record->last_evaluation_status, ['skipped_missing_reporter', 'skipped_stale_reporter', 'skipped_unreadable_metric'], true)) {
            if ($record->is_triggered) {
                return match ($record->last_evaluation_status) {
                    'skipped_missing_reporter' => 'Reporter data is missing; alert remains triggered until fresh data confirms recovery.',
                    'skipped_stale_reporter' => 'Reporter data is stale; alert remains triggered until a fresh sample confirms recovery.',
                    default => 'Reporter data is unreadable; alert remains triggered until a readable sample confirms recovery.',
                };
            }

            return $record->last_evaluation_reason;
        }

        if ($record->last_evaluated_value === null) {
            return null;
        }

        return 'Last checked '.$this->formatMetricValue($record->last_evaluated_value).' '.$record->operator.' '.$this->formatMetricValue($record->value);
    }

    private function lastValueDescription($record): ?string
    {
        if ($record->last_evaluation_status === 'skipped_stale_reporter') {
            return $record->last_reported_at instanceof Carbon
                ? 'Last reporter sample '.$record->last_reported_at->diffForHumans()
                : 'Reporter data is stale';
        }

        if ($record->last_evaluation_status === 'skipped_missing_reporter') {
            return 'No reporter samples received';
        }

        if ($record->last_evaluation_status === 'skipped_unreadable_metric') {
            return $record->last_reported_at instanceof Carbon
                ? 'Latest reporter sample '.$record->last_reported_at->diffForHumans()
                : 'Reporter sample is unreadable';
        }

        return $record->last_evaluated_at?->diffForHumans();
    }

    private function formatMetricValue(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
    }
}
