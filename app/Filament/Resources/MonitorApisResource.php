<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasUnhealthyNavigationBadge;
use App\Filament\Resources\MonitorApis\Schemas\MonitorApiInfolist;
use App\Filament\Resources\MonitorApisResource\Pages;
use App\Filament\Resources\MonitorApisResource\RelationManagers;
use App\Filament\Resources\Support\MonitorSnoozeAction;
use App\Filament\Support\HealthStatusFilter;
use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Services\IntervalParser;
use App\Support\ApiMonitorEvidenceFormatter;
use App\Support\HealthStatusLabel;
use App\Support\PackageCheckTableEvidence;
use App\Support\ScheduledFailureStreak;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;

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
            ->withAvg([
                'results as avg_response_time' => fn (Builder $query): Builder => $query->scheduled(),
            ], 'response_time_ms')
            ->with(['latestResult', 'latestScheduledResult', 'latestDiagnosticResult'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    protected static function scopeUnhealthyNavigationBadgeQuery(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    protected static function navigationBadgeBaseQuery(): Builder
    {
        return MonitorApis::query()->where('created_by', auth()->id());
    }

    public static function canRunDiagnostic(MonitorApis $record): bool
    {
        return ! $record->trashed() && (bool) $record->is_enabled;
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
                        Forms\Components\Select::make('project_id')
                            ->label('Application')
                            ->options(fn (): array => Project::query()
                                ->where('created_by', auth()->id())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false)
                            ->disabled(fn (?MonitorApis $record): bool => $record?->source === 'package')
                            ->helperText('Attach this manual API monitor to an application so its incidents and health count toward that application.')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('package_interval')
                            ->label('Polling Interval')
                            ->options(static::pollingIntervalOptions())
                            ->default('5m')
                            ->required()
                            ->native(false)
                            ->disabled(fn (?MonitorApis $record): bool => $record?->source === 'package')
                            ->dehydrated(fn (?MonitorApis $record): bool => $record?->source !== 'package')
                            ->helperText('Checkybot evaluates due monitors every minute. Pick the intended polling cadence so new manual monitors do not fall back to every-minute scheduling.'),
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
                        Forms\Components\TextInput::make('max_response_time_ms')
                            ->label('Response-time warning (ms)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120000)
                            ->helperText('Optional threshold that marks a successful API check as warning when it responds too slowly.'),
                        Forms\Components\TextInput::make('data_path')
                            ->helperText('Optional legacy single JSON path check. Use response assertions below for richer API checks.')
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
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (blank($state)) {
                                    $set('request_body', null);
                                }
                            })
                            ->native(false)
                            ->nullable()
                            ->helperText('Optional body format for POST, PUT, PATCH, and DELETE requests.'),
                        Forms\Components\Textarea::make('request_body')
                            ->label('Request Body')
                            ->rows(8)
                            ->maxLength(65535)
                            ->helperText('Use JSON for JSON and form bodies, or plain text for raw bodies.')
                            ->columnSpanFull()
                            ->hidden(fn (Get $get): bool => blank($get('request_body_type')))
                            ->mutateStateForValidationUsing(function (mixed $state, Get $get): mixed {
                                if (! is_string($state) || $state === '' || trim($state) !== '') {
                                    return $state;
                                }

                                // Laravel skips non-implicit rules for blank values; force whitespace through validation.
                                if (in_array($get('request_body_type'), ['json', 'form'], true)) {
                                    return '__checkybot_whitespace_request_body__';
                                }

                                return str_repeat('_', strlen($state));
                            })
                            ->rule(function (Get $get): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if ($value === null || $value === '' || ! in_array($get('request_body_type'), ['json', 'form'], true)) {
                                        return;
                                    }

                                    $decoded = json_decode((string) $value, true);

                                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                                        $fail('The request body must be a JSON object or array for JSON or form body types.');
                                    }
                                };
                            }),
                    ]),
                Section::make('Response Assertions')
                    ->description('Add JSON response checks now so the first scheduled run validates the fields that prove this endpoint is healthy.')
                    ->visibleOn('create')
                    ->schema([
                        Forms\Components\Repeater::make('assertions')
                            ->schema(static::assertionFormSchema())
                            ->columns(2)
                            ->default([])
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => filled($state['data_path'] ?? null)
                                ? ($state['data_path'].' - '.str_replace('_', ' ', (string) ($state['assertion_type'] ?? 'assertion')))
                                : 'New assertion')
                            ->addActionLabel('Add assertion')
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->maxItems(50)
                            ->columnSpanFull(),
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

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function assertionFormSchema(bool $includeSortOrder = false): array
    {
        $schema = [
            Forms\Components\TextInput::make('data_path')
                ->required()
                ->label('JSON Path')
                ->helperText('Path to the value in the JSON response, for example data.user.id.')
                ->maxLength(255),

            Forms\Components\Select::make('assertion_type')
                ->required()
                ->label('Assertion')
                ->options([
                    'type_check' => 'Check Type',
                    'value_compare' => 'Compare Value',
                    'exists' => 'Value Exists',
                    'not_exists' => 'Value Does Not Exist',
                    'array_length' => 'Array Length',
                    'regex_match' => 'Regex Match',
                ])
                ->default('exists')
                ->native(false)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $set('expected_type', null);
                    $set('comparison_operator', null);
                    $set('expected_value', null);
                    $set('regex_pattern', null);
                }),

            Forms\Components\Select::make('expected_type')
                ->options([
                    'string' => 'String',
                    'integer' => 'Integer',
                    'boolean' => 'Boolean',
                    'array' => 'Array',
                    'object' => 'Object',
                    'float' => 'Float',
                    'null' => 'Null',
                ])
                ->native(false)
                ->required(fn (Get $get): bool => $get('assertion_type') === 'type_check')
                ->visible(fn (Get $get): bool => $get('assertion_type') === 'type_check'),

            Forms\Components\Select::make('comparison_operator')
                ->options(fn (Get $get): array => static::comparisonOperatorOptions($get('assertion_type')))
                ->native(false)
                ->required(fn (Get $get): bool => in_array($get('assertion_type'), ['value_compare', 'array_length'], true))
                ->visible(fn (Get $get): bool => in_array($get('assertion_type'), ['value_compare', 'array_length'], true))
                ->rules(fn (Get $get): array => in_array($get('assertion_type'), ['value_compare', 'array_length'], true)
                    ? ['in:'.implode(',', array_keys(static::comparisonOperatorOptions($get('assertion_type'))))]
                    : []),

            Forms\Components\TextInput::make('expected_value')
                ->required(fn (Get $get): bool => in_array($get('assertion_type'), ['value_compare', 'array_length'], true))
                ->visible(fn (Get $get): bool => in_array($get('assertion_type'), ['value_compare', 'array_length'], true))
                ->label(fn (Get $get): string => $get('assertion_type') === 'array_length' ? 'Expected Length' : 'Expected Value')
                ->rules(fn (Get $get): array => $get('assertion_type') === 'array_length' ? ['integer', 'min:0'] : [])
                ->maxLength(255),

            Forms\Components\TextInput::make('regex_pattern')
                ->required(fn (Get $get): bool => $get('assertion_type') === 'regex_match')
                ->visible(fn (Get $get): bool => $get('assertion_type') === 'regex_match')
                ->rules(fn (Get $get): array => $get('assertion_type') === 'regex_match'
                    ? [
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! is_string($value) || ! MonitorApiAssertion::hasValidRegexPattern($value)) {
                                $fail('Enter a valid regular expression pattern, including delimiters such as /pattern/.');
                            }
                        },
                    ]
                    : [])
                ->helperText('Regular expression pattern, for example /^[0-9]+$/.')
                ->placeholder('/pattern/')
                ->maxLength(1000),

            Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ];

        if ($includeSortOrder) {
            $schema[] = Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->label('Sort Order')
                ->helperText('Lower numbers are evaluated first.');
        }

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    private static function comparisonOperatorOptions(?string $assertionType): array
    {
        $options = [
            '=' => 'Equals',
            '!=' => 'Not Equals',
            '>' => 'Greater Than',
            '<' => 'Less Than',
            '>=' => 'Greater Than or Equal',
            '<=' => 'Less Than or Equal',
        ];

        if ($assertionType !== 'array_length') {
            $options['contains'] = 'Contains';
        }

        return $options;
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
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->color(fn (?string $state): string => HealthStatusLabel::color($state)),
                Tables\Columns\TextColumn::make('latest_failure_evidence')
                    ->label('Latest Evidence')
                    ->state(fn (MonitorApis $record): ?string => ApiMonitorEvidenceFormatter::compactLatestEvidence($record->latestResult))
                    ->placeholder('No runs yet')
                    ->color(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::statusColor($record->latestResult?->status ?? $record->current_status))
                    ->wrap(),
                Tables\Columns\TextColumn::make('scheduled_failure_streak')
                    ->label('Failure Streak')
                    ->state(function (MonitorApis $record): ?string {
                        $latestScheduledResult = $record->latestScheduledResult;

                        if (
                            $latestScheduledResult === null
                            || (
                                ! in_array($latestScheduledResult->status, ['warning', 'danger'], true)
                                && $latestScheduledResult->is_success !== false
                            )
                        ) {
                            return null;
                        }

                        return ScheduledFailureStreak::displayForApi($record);
                    })
                    ->placeholder('-')
                    ->color('danger')
                    ->wrap(),
                Tables\Columns\TextColumn::make('package_interval')
                    ->label('Interval')
                    ->state(fn (MonitorApis $record): string => PackageCheckTableEvidence::displayInterval($record->package_interval) ?? 'Missing')
                    ->badge()
                    ->description(fn (MonitorApis $record): string => PackageCheckTableEvidence::dueDescription($record))
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
                        if (! $state) {
                            $record->forceFill(MonitorApis::disabledHealthAttributes())->save();
                        }

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
                    ->state(function (MonitorApis $record): string {
                        $average = array_key_exists('avg_response_time', $record->getAttributes())
                            ? $record->avg_response_time
                            : $record->results()->scheduled()->avg('response_time_ms');

                        return $average === null ? '-' : (string) round($average);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
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
                \Filament\Actions\Action::make('run_now')
                    ->label('Run check now')
                    ->color('primary')
                    ->icon('heroicon-o-bolt')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-bolt')
                    ->modalHeading('Run API monitor now')
                    ->modalDescription('Checkybot will queue a real request against this endpoint, append the result to run history, update live status, and alert subscribers on status changes.')
                    ->modalSubmitActionLabel('Run now')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                    ->visible(fn (MonitorApis $record): bool => static::canRunDiagnostic($record))
                    ->disabled(fn (MonitorApis $record): bool => $record->hasQueuedDiagnostic())
                    ->action(function (MonitorApis $record): void {
                        $queuedStatePersisted = false;

                        try {
                            $record->refresh();

                            if (! static::canRunDiagnostic($record)) {
                                Notification::make()
                                    ->title('Diagnostic unavailable')
                                    ->body('Archived or disabled API monitors cannot run fresh diagnostics.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ($record->hasQueuedDiagnostic()) {
                                Notification::make()
                                    ->title('Diagnostic already queued')
                                    ->body('Checkybot is already waiting for this API monitor diagnostic to finish.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->forceFill([
                                'diagnostic_queued_at' => now(),
                            ])->save();
                            $queuedStatePersisted = true;

                            RunApiMonitorDiagnosticJob::dispatch($record->withoutRelations());
                        } catch (\Throwable $e) {
                            if ($queuedStatePersisted) {
                                $record->forceFill([
                                    'diagnostic_queued_at' => null,
                                ])->save();
                            }

                            Log::error('Run Now API monitor diagnostic dispatch failed from table action', [
                                'monitor_api_id' => $record->id,
                                'exception' => $e,
                            ]);

                            Notification::make()
                                ->title('Diagnostic could not be queued')
                                ->body('Checkybot could not queue the on-demand check. Check the application logs for details.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Diagnostic queued')
                            ->body('Checkybot will run this API monitor in the background and add the evidence to diagnostic history.')
                            ->success()
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
                                : MonitorApis::query()->whereIn('id', $ids)->update([
                                    'is_enabled' => true,
                                    'project_paused_monitoring' => false,
                                ]);

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
                                : MonitorApis::query()->whereIn('id', $ids)->update([
                                    'is_enabled' => false,
                                    'project_paused_monitoring' => false,
                                ] + MonitorApis::disabledHealthAttributes());

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
                                ->options(static::pollingIntervalOptions())
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $interval = IntervalParser::normalizeOrFail($data['interval'], 'interval');

                            $editableRecords = $records
                                ->reject(fn (MonitorApis $monitor): bool => $monitor->source === 'package');
                            $skippedCount = $records->count() - $editableRecords->count();

                            $ids = $editableRecords
                                ->reject(fn (MonitorApis $monitor): bool => $monitor->package_interval === $interval)
                                ->pluck('id');

                            $count = $ids->isEmpty()
                                ? 0
                                : MonitorApis::query()->whereIn('id', $ids)->update(['package_interval' => $interval]);

                            $title = $count === 0
                                ? 'Nothing to update'
                                : ($count === 1 ? '1 API monitor updated' : "{$count} API monitors updated");

                            if ($skippedCount > 0 && $count > 0) {
                                $title .= $skippedCount === 1
                                    ? ', 1 package-managed monitor skipped'
                                    : ", {$skippedCount} package-managed monitors skipped";
                            }

                            Notification::make()
                                ->title($title)
                                ->body(match (true) {
                                    $count === 0 && $skippedCount > 0 => $skippedCount === $records->count()
                                        ? 'Package-managed API monitor intervals are controlled by the package and were not changed.'
                                        : "Editable monitors already run every {$interval}; package-managed schedules were not changed.",
                                    $count === 0 => "All selected API monitors already run every {$interval}.",
                                    $skippedCount > 0 => "New polling interval: {$interval}. Package-managed schedules stay controlled by the package.",
                                    default => "New polling interval: {$interval}.",
                                })
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

    protected static function pollingIntervalOptions(): array
    {
        return [
            '1m' => 'Every minute',
            '5m' => 'Every 5 minutes',
            '10m' => 'Every 10 minutes',
            '15m' => 'Every 15 minutes',
            '30m' => 'Every 30 minutes',
            '1h' => 'Every hour',
            '6h' => 'Every 6 hours',
            '12h' => 'Every 12 hours',
            '1d' => 'Every 24 hours',
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\NotificationSettingsRelationManager::class,
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
