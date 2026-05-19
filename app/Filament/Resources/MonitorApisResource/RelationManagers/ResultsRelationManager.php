<?php

namespace App\Filament\Resources\MonitorApisResource\RelationManagers;

use App\Enums\RunSource;
use App\Enums\UptimeTransportErrorType;
use App\Filament\Resources\MonitorApisResource\Widgets\ResponseTimeChart;
use App\Models\MonitorApiResult;
use App\Support\ApiMonitorEvidenceFormatter;
use App\Support\HealthStatusLabel;
use App\Support\UptimeTransportError;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    protected static ?string $title = 'Run History';

    protected static ?string $recordTitleAttribute = 'created_at';

    protected static ?string $inverseRelationship = 'monitorApi';

    protected function getHeaderWidgetsData(): array
    {
        return [
            'record' => $this->getOwnerRecord(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ResponseTimeChart::make(['record' => $this->getOwnerRecord()]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->color(fn (?string $state): string => HealthStatusLabel::color($state)),

                Tables\Columns\TextColumn::make('run_source')
                    ->label('Run')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                    ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Summary')
                    ->wrap()
                    ->limit(90)
                    ->default('-'),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Response Time')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('elapsed_wall_time_ms')
                    ->label('Wall Time')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-')
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('http_code')
                    ->label('HTTP Code')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === 0 ? 'No response' : (string) ($state ?? '-'))
                    ->color(fn (?int $state): string => ApiMonitorEvidenceFormatter::httpCodeColor($state)),

                Tables\Columns\TextColumn::make('transport_error_type')
                    ->label('Transport')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : UptimeTransportError::label($state))
                    ->color(fn (?string $state): string => UptimeTransportError::color($state))
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('failed_assertions')
                    ->label('Failed Assertions')
                    ->state(fn (MonitorApiResult $record): int => count($record->failed_assertions ?? []))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTimeInUserZone()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->filters([
                Tables\Filters\SelectFilter::make('is_success')
                    ->label('Status')
                    ->options([
                        '1' => 'Success',
                        '0' => 'Failed',
                    ]),
                Tables\Filters\Filter::make('high_response_time')
                    ->label('High Response Time')
                    ->query(fn ($query) => $query->where('response_time_ms', '>', 1000)),
                Tables\Filters\SelectFilter::make('run_source')
                    ->label('Run')
                    ->options(RunSource::options()),
                Tables\Filters\SelectFilter::make('transport_error_type')
                    ->label('Transport error')
                    ->options(static::transportErrorOptions()),
                Tables\Filters\Filter::make('assertion_failures')
                    ->label('Assertion failures')
                    ->query(fn ($query) => $query->whereJsonLength('failed_assertions', '>', 0)),
                Tables\Filters\Filter::make('assertion_path')
                    ->label('Assertion path')
                    ->schema([
                        TextInput::make('path')
                            ->label('Assertion path')
                            ->placeholder('data.parsed'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => static::filterByFailedAssertionPath($query, $data))
                    ->indicateUsing(fn (array $data): array => filled($data['path'] ?? null)
                        ? ['path' => 'Assertion path: '.trim((string) $data['path'])]
                        : []),
                Tables\Filters\Filter::make('no_response')
                    ->label('No response')
                    ->query(fn ($query) => $query->where('http_code', 0)),
                Tables\Filters\Filter::make('http_4xx')
                    ->label('HTTP 4xx')
                    ->query(fn ($query) => $query->whereBetween('http_code', [400, 499])),
                Tables\Filters\Filter::make('http_5xx')
                    ->label('HTTP 5xx')
                    ->query(fn ($query) => $query->whereBetween('http_code', [500, 599])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View Evidence')
                    ->modalWidth('5xl'),
            ])
            ->bulkActions([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run Overview')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                            ->color(fn (?string $state): string => HealthStatusLabel::color($state)),
                        TextEntry::make('run_source')
                            ->label('Run')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                            ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                        TextEntry::make('summary')
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('http_code')
                            ->label('HTTP Code')
                            ->badge()
                            ->formatStateUsing(fn (?int $state): string => $state === 0 ? 'No response' : (string) ($state ?? '-'))
                            ->color(fn (?int $state): string => ApiMonitorEvidenceFormatter::httpCodeColor($state)),
                        TextEntry::make('transport_error_type')
                            ->label('Transport Error')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->color(fn (?string $state): string => UptimeTransportError::color($state))
                            ->visible(fn (MonitorApiResult $record): bool => filled($record->transport_error_type)),
                        TextEntry::make('response_time_ms')
                            ->label('Response Time')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-'),
                        TextEntry::make('elapsed_wall_time_ms')
                            ->label('Elapsed Wall Time')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-'),
                        TextEntry::make('effective_timeout_seconds')
                            ->label('Effective Timeout')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}s" : '-'),
                        TextEntry::make('retry_count')
                            ->label('Retries')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? (string) $state : '-'),
                        TextEntry::make('created_at')
                            ->label('Captured At')
                            ->dateTimeInUserZone(),
                    ])
                    ->columns(3),
                Section::make('Transport Evidence')
                    ->hidden(fn (MonitorApiResult $record): bool => blank($record->transport_error_type))
                    ->schema([
                        TextEntry::make('transport_error_type')
                            ->label('Classification')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->color(fn (?string $state): string => UptimeTransportError::color($state)),
                        TextEntry::make('transport_error_code')
                            ->label('cURL Error Code')
                            ->placeholder('-')
                            ->copyable(),
                        TextEntry::make('transport_error_message')
                            ->label('Message')
                            ->copyable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Failed Assertions')
                    ->hidden(fn (MonitorApiResult $record): bool => blank($record->failed_assertions))
                    ->schema([
                        RepeatableEntry::make('failed_assertions')
                            ->label('')
                            ->state(fn (MonitorApiResult $record): array => ApiMonitorEvidenceFormatter::normalizeAssertions($record->failed_assertions))
                            ->schema([
                                TextEntry::make('path')
                                    ->badge()
                                    ->color('danger'),
                                TextEntry::make('type')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('message')
                                    ->columnSpanFull(),
                                TextEntry::make('expected')
                                    ->label('Expected')
                                    ->icon('heroicon-o-flag')
                                    ->iconColor('info')
                                    ->copyable(),
                                TextEntry::make('actual')
                                    ->label('Actual')
                                    ->icon('heroicon-o-x-circle')
                                    ->iconColor('danger')
                                    ->copyable(),
                            ])
                            ->contained(false)
                            ->columns(2),
                    ]),
                Section::make('Header Snapshots')
                    ->hidden(fn (MonitorApiResult $record): bool => blank($record->request_headers) && blank($record->response_headers))
                    ->schema([
                        KeyValueEntry::make('request_headers')
                            ->label('Request Headers')
                            ->state(fn (MonitorApiResult $record): array => $record->request_headers ?? [])
                            ->hidden(fn (MonitorApiResult $record): bool => blank($record->request_headers)),
                        KeyValueEntry::make('response_headers')
                            ->label('Response Headers')
                            ->state(fn (MonitorApiResult $record): array => $record->response_headers ?? [])
                            ->hidden(fn (MonitorApiResult $record): bool => blank($record->response_headers)),
                    ])
                    ->columns(2),
                Section::make('Saved Failure Payload')
                    ->hidden(fn (MonitorApiResult $record): bool => blank($record->response_body))
                    ->schema([
                        TextEntry::make('response_body')
                            ->label('')
                            ->state(fn (MonitorApiResult $record): string => ApiMonitorEvidenceFormatter::formatPayload($record->response_body))
                            ->html()
                            ->formatStateUsing(fn (string $state) => ApiMonitorEvidenceFormatter::formatAsPreHtml($state))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function transportErrorOptions(): array
    {
        return collect(UptimeTransportErrorType::cases())
            ->mapWithKeys(fn (UptimeTransportErrorType $type): array => [
                $type->value => UptimeTransportError::label($type),
            ])
            ->all();
    }

    private static function filterByFailedAssertionPath(Builder $query, array $data): Builder
    {
        $path = trim((string) ($data['path'] ?? ''));

        if ($path === '') {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn('failed_assertions');
        $driver = $query->getConnection()->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => $query->whereRaw(
                "JSON_CONTAINS({$column}, JSON_OBJECT('path', ?), '$')",
                [$path],
            ),
            'pgsql' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM jsonb_array_elements({$column}::jsonb) assertion WHERE assertion->>'path' = ?)",
                [$path],
            ),
            'sqlite' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM json_each({$column}) WHERE json_extract(json_each.value, '$.path') = ?)",
                [$path],
            ),
            default => $query->where($column, 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], '"path":'.json_encode($path)).'%'),
        };
    }
}
