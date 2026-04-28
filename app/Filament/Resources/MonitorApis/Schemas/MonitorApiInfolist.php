<?php

namespace App\Filament\Resources\MonitorApis\Schemas;

use App\Enums\RunSource;
use App\Models\MonitorApis;
use App\Support\ApiMonitorEvidenceFormatter;
use App\Support\UptimeTransportError;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MonitorApiInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Overview')
                    ->schema([
                        TextEntry::make('current_status')
                            ->label('Latest Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => ApiMonitorEvidenceFormatter::statusColor($state)),
                        TextEntry::make('status_summary')
                            ->label('Latest Summary')
                            ->default('No runs recorded yet.')
                            ->columnSpanFull(),
                        TextEntry::make('latest_result_timestamp')
                            ->label('Latest Run')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->created_at?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (MonitorApis $record): ?string => $record->latestResult?->created_at?->diffForHumans()),
                        TextEntry::make('latest_result_http_code')
                            ->label('Latest HTTP Code')
                            ->state(fn (MonitorApis $record): ?int => $record->latestResult?->http_code)
                            ->default('-')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => (string) $state === '0' ? 'No response' : (string) ($state ?? '-'))
                            ->color(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::httpCodeColor($record->latestResult?->http_code)),
                        TextEntry::make('latest_result_transport_error')
                            ->label('Latest Transport Error')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->transport_error_type)
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type))
                            ->badge()
                            ->color(fn (?string $state): string => UptimeTransportError::color($state)),
                        TextEntry::make('latest_result_response_time')
                            ->label('Latest Response Time')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->response_time_ms !== null ? "{$record->latestResult->response_time_ms}ms" : null)
                            ->default('-'),
                        TextEntry::make('last_heartbeat_at')
                            ->label('Last Heartbeat')
                            ->state(fn (MonitorApis $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (MonitorApis $record): ?string => $record->last_heartbeat_at?->diffForHumans()),
                    ])
                    ->columns(3),
                Section::make('Request Configuration')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('http_method')
                            ->label('Method')
                            ->badge(),
                        TextEntry::make('expected_status')
                            ->label('Expected Status')
                            ->default('-'),
                        TextEntry::make('url')
                            ->columnSpanFull()
                            ->copyable(),
                        TextEntry::make('data_path')
                            ->default('-'),
                        TextEntry::make('save_failed_response')
                            ->label('Save Failure Payloads')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        KeyValueEntry::make('request_headers')
                            ->label('Configured Headers')
                            ->state(fn (MonitorApis $record): array => ApiMonitorEvidenceFormatter::maskHeaders($record->headers))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->headers))
                            ->columnSpanFull(),
                        TextEntry::make('request_body_type')
                            ->label('Body Type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? strtoupper($state) : 'None')
                            ->color('gray'),
                        TextEntry::make('has_request_body')
                            ->label('Request Body')
                            ->state('Configured')
                            ->hidden(fn (MonitorApis $record): bool => blank($record->request_body))
                            ->badge()
                            ->color('gray'),
                    ])
                    ->columns(3),
                Section::make('Latest Run Evidence')
                    ->hidden(fn (MonitorApis $record): bool => $record->latestResult === null)
                    ->schema([
                        TextEntry::make('latestResult.status')
                            ->label('Run Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => ApiMonitorEvidenceFormatter::statusColor($state)),
                        TextEntry::make('latestResult.run_source')
                            ->label('Run')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                            ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                        TextEntry::make('latestResult.summary')
                            ->label('Run Summary')
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('latestResult.transport_error_type')
                            ->label('Transport Error')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->color(fn (?string $state): string => UptimeTransportError::color($state))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type)),
                        TextEntry::make('latestResult.transport_error_code')
                            ->label('cURL Error Code')
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type))
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('latestResult.transport_error_message')
                            ->label('Transport Message')
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type))
                            ->copyable()
                            ->columnSpanFull(),
                        RepeatableEntry::make('latest_failed_assertions')
                            ->label('Failed Assertions')
                            ->state(fn (MonitorApis $record): array => ApiMonitorEvidenceFormatter::normalizeAssertions($record->latestResult?->failed_assertions))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->failed_assertions))
                            ->schema([
                                TextEntry::make('path')
                                    ->badge()
                                    ->color('danger'),
                                TextEntry::make('type')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('message')
                                    ->columnSpanFull(),
                            ])
                            ->contained(false)
                            ->columns(2)
                            ->columnSpanFull(),
                        KeyValueEntry::make('latest_request_headers')
                            ->label('Request Headers Snapshot')
                            ->state(fn (MonitorApis $record): array => $record->latestResult?->request_headers ?? [])
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->request_headers)),
                        KeyValueEntry::make('latest_response_headers')
                            ->label('Response Headers Snapshot')
                            ->state(fn (MonitorApis $record): array => $record->latestResult?->response_headers ?? [])
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->response_headers)),
                        TextEntry::make('latest_response_body')
                            ->label('Saved Failure Payload')
                            ->state(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::formatPayload($record->latestResult?->response_body))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->response_body))
                            ->html()
                            ->formatStateUsing(fn (string $state) => ApiMonitorEvidenceFormatter::formatAsPreHtml($state))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
