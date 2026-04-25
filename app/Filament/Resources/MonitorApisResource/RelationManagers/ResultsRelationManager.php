<?php

namespace App\Filament\Resources\MonitorApisResource\RelationManagers;

use App\Filament\Resources\MonitorApisResource\Widgets\ResponseTimeChart;
use App\Models\MonitorApiResult;
use App\Support\ApiMonitorEvidenceFormatter;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

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
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => ApiMonitorEvidenceFormatter::statusColor($state)),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Summary')
                    ->wrap()
                    ->limit(90)
                    ->default('-'),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Response Time')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('http_code')
                    ->label('HTTP Code')
                    ->badge()
                    ->color(fn (?int $state): string => ApiMonitorEvidenceFormatter::httpCodeColor($state)),

                Tables\Columns\TextColumn::make('failed_assertions')
                    ->label('Failed Assertions')
                    ->state(fn (MonitorApiResult $record): int => count($record->failed_assertions ?? []))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
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
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => ApiMonitorEvidenceFormatter::statusColor($state)),
                        TextEntry::make('summary')
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('http_code')
                            ->label('HTTP Code')
                            ->badge()
                            ->color(fn (?int $state): string => ApiMonitorEvidenceFormatter::httpCodeColor($state)),
                        TextEntry::make('response_time_ms')
                            ->label('Response Time')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}ms" : '-'),
                        TextEntry::make('created_at')
                            ->label('Captured At')
                            ->dateTime(),
                    ])
                    ->columns(3),
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
}
