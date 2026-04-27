<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasUnhealthyNavigationBadge;
use App\Filament\Resources\MonitorApis\Schemas\MonitorApiInfolist;
use App\Filament\Resources\MonitorApisResource\Pages;
use App\Filament\Resources\MonitorApisResource\RelationManagers;
use App\Filament\Resources\Support\MonitorSnoozeAction;
use App\Filament\Support\HealthStatusFilter;
use App\Models\MonitorApis;
use App\Services\IntervalParser;
use App\Support\ApiMonitorTestNotification;
use App\Support\PackageCheckTableEvidence;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MonitorApisResource extends Resource
{
    use HasUnhealthyNavigationBadge;

    protected static ?string $model = MonitorApis::class;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'API Monitors';

    protected static ?string $modelLabel = 'API Monitor';

    protected static ?string $pluralModelLabel = 'API Monitors';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('created_by', auth()->id())
            ->withAvg('results as avg_response_time', 'response_time_ms')
            ->with(['latestResult'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Monitor Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->helperText('Disable this monitor to keep its configuration without running scheduled checks.')
                            ->default(true)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('url')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Request Settings')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('http_method')
                            ->label('HTTP Method')
                            ->options([
                                'GET' => 'GET',
                                'POST' => 'POST',
                                'PUT' => 'PUT',
                                'PATCH' => 'PATCH',
                                'DELETE' => 'DELETE',
                                'HEAD' => 'HEAD',
                                'OPTIONS' => 'OPTIONS',
                            ])
                            ->default('GET')
                            ->required(),
                        Forms\Components\TextInput::make('expected_status')
                            ->label('Expected Status Code')
                            ->numeric()
                            ->default(200)
                            ->minValue(100)
                            ->maxValue(599)
                            ->required()
                            ->helperText('The response status code this monitor should treat as healthy.'),
                        Forms\Components\TextInput::make('timeout_seconds')
                            ->label('Timeout (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->placeholder((string) config('monitor.api_timeout', 10))
                            ->helperText('Optional override for slow endpoints. Leave blank to use the default timeout.'),
                        Forms\Components\TextInput::make('data_path')
                            ->helperText('Optional JSON path to validate in the response body (for example: data.items).')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\KeyValue::make('headers')
                            ->keyLabel('Header')
                            ->valueLabel('Value')
                            ->keyPlaceholder('Header Name')
                            ->valuePlaceholder('Header Value')
                            ->helperText('Optional headers to include in the request')
                            ->columnSpanFull()
                            ->addActionLabel('Add Header'),
                        Forms\Components\Select::make('request_body_type')
                            ->label('Request Body Type')
                            ->options([
                                'json' => 'JSON',
                                'form' => 'Form URL Encoded',
                                'raw' => 'Raw',
                            ])
                            ->native(false)
                            ->nullable()
                            ->helperText('Optional body format for POST, PUT, PATCH, and DELETE requests.'),
                        Forms\Components\Textarea::make('request_body')
                            ->label('Request Body')
                            ->rows(8)
                            ->maxLength(65535)
                            ->helperText('Use JSON for JSON and form bodies, or plain text for raw bodies.')
                            ->columnSpanFull()
                            ->rule(function (Get $get): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if (blank($value) || ! in_array($get('request_body_type'), ['json', 'form'], true)) {
                                        return;
                                    }

                                    json_decode((string) $value, true);

                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        $fail('The request body must be valid JSON for JSON or form body types.');
                                    }
                                };
                            }),
                    ]),
                Section::make('Failure Handling')
                    ->schema([
                        Forms\Components\Toggle::make('save_failed_response')
                            ->label('Save Response Body on Failure')
                            ->helperText('When enabled, the full response body will be saved when assertions fail')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->searchable(),
                Tables\Columns\TextColumn::make('current_status')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('package_interval')
                    ->label('Interval')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => PackageCheckTableEvidence::displayInterval($state) ?? 'Every minute')
                    ->description(fn (MonitorApis $record): string => static::scheduledIntervalDescription($record))
                    ->color('gray'),
                Tables\Columns\TextColumn::make('silenced_until')
                    ->label('Snoozed')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-bell-slash')
                    ->state(fn (MonitorApis $record): ?string => $record->isSilenced()
                        ? 'Until '.$record->silenced_until->format('M j, H:i')
                        : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_enabled')
                    ->label('Enabled')
                    ->tooltip(fn (): string => auth()->user()?->can('Update:MonitorApis')
                        ? 'Pause or resume scheduled checks for this monitor.'
                        : 'You need the Update:MonitorApis permission to change this.')
                    ->disabled(fn (): bool => ! (auth()->user()?->can('Update:MonitorApis') ?? false))
                    ->beforeStateUpdated(function (): void {
                        abort_unless(auth()->user()?->can('Update:MonitorApis') ?? false, 403);
                    })
                    ->afterStateUpdated(function (MonitorApis $record, bool $state): void {
                        $notification = Notification::make()
                            ->title($state ? "{$record->title} enabled" : "{$record->title} disabled")
                            ->body($state
                                ? 'Scheduled checks will resume on the next run.'
                                : 'Scheduled checks are paused. Configuration and history are preserved.');

                        ($state ? $notification->success() : $notification->warning())
                            ->send();
                    }),
                Tables\Columns\TextColumn::make('data_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('avg_response_time')
                    ->label('Avg Response Time (ms)')
                    ->default('-')
                    ->formatStateUsing(fn ($state) => $state === '-' ? '-' : round($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                HealthStatusFilter::make(),
                HealthStatusFilter::onlyFailing(
                    activeScope: fn (Builder $query): Builder => $query->where('is_enabled', true),
                ),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('snooze')
                    ->label(fn (MonitorApis $record): string => $record->isSilenced() ? 'Snoozed' : 'Snooze')
                    ->icon('heroicon-o-bell-slash')
                    ->color(fn (MonitorApis $record): string => $record->isSilenced() ? 'warning' : 'gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                    ->modalHeading('Snooze notifications for this API monitor')
                    ->modalDescription('Suppress alert delivery during a maintenance window. Checks keep running, the dashboard keeps updating, but no emails or webhooks fire while snoozed.')
                    ->modalSubmitActionLabel('Snooze')
                    ->fillForm(fn (MonitorApis $record): array => [
                        'duration' => $record->isSilenced() ? 'custom' : '1h',
                        'until' => $record->silenced_until,
                    ])
                    ->schema(MonitorSnoozeAction::formSchema())
                    ->action(function (MonitorApis $record, array $data): void {
                        $until = MonitorSnoozeAction::resolveUntil($data);

                        if ($until === null) {
                            Notification::make()
                                ->title('Snooze time must be in the future')
                                ->body('Pick a future moment, or use Unsnooze to clear the silence.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['silenced_until' => $until]);

                        Notification::make()
                            ->title('Notifications snoozed')
                            ->body("Alerts paused for {$record->title} until {$until->format('M j, Y H:i')}.")
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\Action::make('unsnooze')
                    ->label('Unsnooze')
                    ->icon('heroicon-o-bell')
                    ->color('gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                    ->visible(fn (MonitorApis $record): bool => $record->isSilenced())
                    ->requiresConfirmation()
                    ->modalHeading('Resume notifications')
                    ->modalDescription('Notifications for this API monitor will fire again on the next status change.')
                    ->modalSubmitActionLabel('Unsnooze')
                    ->action(function (MonitorApis $record): void {
                        $record->update(['silenced_until' => null]);

                        Notification::make()
                            ->title('Notifications resumed')
                            ->body("{$record->title} will alert again on the next status change.")
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\Action::make('test')
                    ->label('Test API')
                    ->color('warning')
                    ->icon('heroicon-o-play')
                    ->action(function (MonitorApis $record): void {
                        $result = MonitorApis::testApi([
                            'id' => $record->id,
                            'url' => $record->url,
                            'method' => $record->http_method,
                            'data_path' => $record->data_path,
                            'headers' => $record->headers,
                            'request_body_type' => $record->request_body_type,
                            'request_body' => $record->request_body,
                            'expected_status' => $record->expected_status,
                            'timeout_seconds' => $record->timeout_seconds,
                            'title' => $record->title,
                        ]);

                        ApiMonitorTestNotification::fromResult($result, $record->expected_status)
                            ->send();
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('enable')
                        ->label('Enable')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Enable selected API monitors')
                        ->modalDescription('Scheduled checks will resume for every selected monitor.')
                        ->modalSubmitActionLabel('Enable')
                        ->action(function (Collection $records): void {
                            $ids = $records->where('is_enabled', false)->pluck('id');
                            $count = $ids->isEmpty()
                                ? 0
                                : MonitorApis::query()->whereIn('id', $ids)->update(['is_enabled' => true]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to enable'
                                    : ($count === 1 ? '1 API monitor enabled' : "{$count} API monitors enabled"))
                                ->body($count === 0
                                    ? 'All selected API monitors were already enabled.'
                                    : 'Scheduled checks will resume on their next run.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    \Filament\Actions\BulkAction::make('disable')
                        ->label('Disable')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Disable selected API monitors')
                        ->modalDescription('Scheduled checks will pause until the monitors are re-enabled. Configuration and history are preserved.')
                        ->modalSubmitActionLabel('Disable')
                        ->action(function (Collection $records): void {
                            $ids = $records->where('is_enabled', true)->pluck('id');
                            $count = $ids->isEmpty()
                                ? 0
                                : MonitorApis::query()->whereIn('id', $ids)->update(['is_enabled' => false]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to disable'
                                    : ($count === 1 ? '1 API monitor disabled' : "{$count} API monitors disabled"))
                                ->body($count === 0
                                    ? 'All selected API monitors were already disabled.'
                                    : 'No new scheduled checks will run until they are re-enabled.')
                                ->warning()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    \Filament\Actions\BulkAction::make('changeInterval')
                        ->label('Change check interval')
                        ->icon('heroicon-o-clock')
                        ->color('gray')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->modalHeading('Change API monitor interval')
                        ->modalDescription('Set how often the selected API monitors should be polled. The scheduler wakes up every minute, but each monitor only runs when its own interval is due.')
                        ->modalSubmitActionLabel('Apply')
                        ->schema([
                            Forms\Components\Select::make('interval')
                                ->label('Interval')
                                ->options([
                                    '1m' => 'Every minute',
                                    '5m' => 'Every 5 minutes',
                                    '10m' => 'Every 10 minutes',
                                    '15m' => 'Every 15 minutes',
                                    '30m' => 'Every 30 minutes',
                                    '1h' => 'Every hour',
                                    '6h' => 'Every 6 hours',
                                    '12h' => 'Every 12 hours',
                                    '1d' => 'Every 24 hours',
                                ])
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $interval = IntervalParser::normalizeOrFail($data['interval'], 'interval');

                            $ids = $records
                                ->reject(fn (MonitorApis $monitor): bool => $monitor->package_interval === $interval)
                                ->pluck('id');

                            $count = $ids->isEmpty()
                                ? 0
                                : MonitorApis::query()->whereIn('id', $ids)->update(['package_interval' => $interval]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to update'
                                    : ($count === 1 ? '1 API monitor updated' : "{$count} API monitors updated"))
                                ->body($count === 0
                                    ? "All selected API monitors already run every {$interval}."
                                    : "New polling interval: {$interval}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    \Filament\Actions\BulkAction::make('snooze')
                        ->label('Snooze notifications')
                        ->icon('heroicon-o-bell-slash')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->modalHeading('Snooze notifications for selected API monitors')
                        ->modalDescription('Suppress alert delivery during a maintenance window. Checks keep running, but no emails or webhooks fire while snoozed.')
                        ->modalSubmitActionLabel('Snooze')
                        ->schema(MonitorSnoozeAction::formSchema())
                        ->action(function (Collection $records, array $data): void {
                            $until = MonitorSnoozeAction::resolveUntil($data);

                            if ($until === null) {
                                Notification::make()
                                    ->title('Snooze time must be in the future')
                                    ->body('Pick a future moment, or use Unsnooze to clear the silence.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $count = MonitorApis::query()
                                ->whereIn('id', $records->pluck('id'))
                                ->update(['silenced_until' => $until]);

                            Notification::make()
                                ->title($count === 1 ? '1 API monitor snoozed' : "{$count} API monitors snoozed")
                                ->body("Alerts paused until {$until->format('M j, Y H:i')}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    \Filament\Actions\BulkAction::make('unsnooze')
                        ->label('Unsnooze')
                        ->icon('heroicon-o-bell')
                        ->color('gray')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Unsnooze selected API monitors')
                        ->modalDescription('Notifications will resume immediately on the next status change for these monitors.')
                        ->modalSubmitActionLabel('Unsnooze')
                        ->action(function (Collection $records): void {
                            $ids = $records->whereNotNull('silenced_until')->pluck('id');
                            $count = $ids->isEmpty()
                                ? 0
                                : MonitorApis::query()->whereIn('id', $ids)->update(['silenced_until' => null]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to unsnooze'
                                    : ($count === 1 ? '1 API monitor unsnoozed' : "{$count} API monitors unsnoozed"))
                                ->body($count === 0
                                    ? 'None of the selected API monitors had an active snooze.'
                                    : 'Notifications will resume on the next status change.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No API monitors yet')
            ->emptyStateDescription('Add your first API monitor to start tracking response time, status codes, and assertions on a schedule.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add API monitor')
                    ->icon('heroicon-o-plus'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MonitorApiInfolist::configure($schema);
    }

    protected static function scheduledIntervalDescription(MonitorApis $record): string
    {
        if (! $record->is_enabled) {
            return 'Paused';
        }

        if (blank($record->package_interval)) {
            return 'Runs every minute';
        }

        try {
            $interval = IntervalParser::fromMinutes(IntervalParser::toMinutes($record->package_interval));
        } catch (\InvalidArgumentException) {
            return 'Runs every minute';
        }

        return "Expected heartbeat every {$interval}; scheduler still runs every minute";
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AssertionsRelationManager::class,
            RelationManagers\ResultsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMonitorApis::route('/'),
            'create' => Pages\CreateMonitorApis::route('/create'),
            'view' => Pages\ViewMonitorApis::route('/{record}'),
            'edit' => Pages\EditMonitorApis::route('/{record}/edit'),
        ];
    }
}
