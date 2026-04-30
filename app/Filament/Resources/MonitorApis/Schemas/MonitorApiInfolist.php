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
                            ->label('Latest Scheduled Run')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->created_at?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->created_at?->diffForHumans()),
                        TextEntry::make('latest_result_http_code')
                            ->label('Latest Scheduled HTTP Code')
                            ->state(fn (MonitorApis $record): ?int => $record->latestScheduledResult?->http_code)
                            ->default('-')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => (string) $state === '0' ? 'No response' : (string) ($state ?? '-'))
                            ->color(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::httpCodeColor($record->latestScheduledResult?->http_code)),
                        TextEntry::make('latest_result_transport_error')
                            ->label('Latest Scheduled Transport Error')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->transport_error_type)
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->transport_error_type))
                            ->badge()
                            ->color(fn (?string $state): string => UptimeTransportError::color($state)),
                        TextEntry::make('latest_result_response_time')
                            ->label('Latest Scheduled Response Time')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->response_time_ms !== null ? "{$record->latestScheduledResult->response_time_ms}ms" : null)
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
                Section::make('Latest Scheduled Run Evidence')
                    ->description('This scheduler-owned evidence is the signal used for live status, dashboards, and alerts.')
                    ->hidden(fn (MonitorApis $record): bool => $record->latestScheduledResult === null)
                    ->schema([
                        TextEntry::make('latest_scheduled_status')
                            ->label('Run Status')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->status)
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => ApiMonitorEvidenceFormatter::statusColor($state)),
                        TextEntry::make('latest_scheduled_run_source')
                            ->label('Evidence Source')
                            ->state(fn (MonitorApis $record): mixed => $record->latestScheduledResult?->run_source)
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                            ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                        TextEntry::make('latest_scheduled_summary')
                            ->label('Run Summary')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->summary)
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('latest_scheduled_transport_error_type')
                            ->label('Transport Error')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->transport_error_type)
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->color(fn (?string $state): string => UptimeTransportError::color($state))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->transport_error_type)),
                        TextEntry::make('latest_scheduled_transport_error_code')
                            ->label('cURL Error Code')
                            ->state(fn (MonitorApis $record): ?int => $record->latestScheduledResult?->transport_error_code)
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->transport_error_type))
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('latest_scheduled_transport_error_message')
                            ->label('Transport Message')
                            ->state(fn (MonitorApis $record): ?string => $record->latestScheduledResult?->transport_error_message)
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->transport_error_type))
                            ->copyable()
                            ->columnSpanFull(),
                        RepeatableEntry::make('latest_failed_assertions')
                            ->label('Failed Assertions')
                            ->state(fn (MonitorApis $record): array => ApiMonitorEvidenceFormatter::normalizeAssertions($record->latestScheduledResult?->failed_assertions))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->failed_assertions))
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
                            ->state(fn (MonitorApis $record): array => $record->latestScheduledResult?->request_headers ?? [])
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->request_headers)),
                        KeyValueEntry::make('latest_response_headers')
                            ->label('Response Headers Snapshot')
                            ->state(fn (MonitorApis $record): array => $record->latestScheduledResult?->response_headers ?? [])
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->response_headers)),
                        TextEntry::make('latest_response_body')
                            ->label('Saved Failure Payload')
                            ->state(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::formatPayload($record->latestScheduledResult?->response_body))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestScheduledResult?->response_body))
                            ->html()
                            ->formatStateUsing(fn (string $state) => ApiMonitorEvidenceFormatter::formatAsPreHtml($state))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Latest Diagnostic Run')
                    ->description('Manual run evidence is appended for triage but does not drive live status, dashboards, or alerts.')
                    ->hidden(fn (MonitorApis $record): bool => $record->latestDiagnosticResult === null)
                    ->schema([
                        TextEntry::make('latest_diagnostic_created_at')
                            ->label('Observed At')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->created_at?->toDayDateTimeString())
                            ->hint(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->created_at?->diffForHumans()),
                        TextEntry::make('latest_diagnostic_status')
                            ->label('Result')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->status)
                            ->default('Unknown')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => ApiMonitorEvidenceFormatter::statusColor($state)),
                        TextEntry::make('latest_diagnostic_http_code')
                            ->label('HTTP Code')
                            ->state(fn (MonitorApis $record): ?int => $record->latestDiagnosticResult?->http_code)
                            ->default('-')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => (string) $state === '0' ? 'No response' : (string) ($state ?? '-'))
                            ->color(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::httpCodeColor($record->latestDiagnosticResult?->http_code)),
                        TextEntry::make('latest_diagnostic_response_time')
                            ->label('Response Time')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->response_time_ms !== null ? "{$record->latestDiagnosticResult->response_time_ms}ms" : null)
                            ->default('-'),
                        TextEntry::make('latest_diagnostic_summary')
                            ->label('Summary')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->summary)
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('latest_diagnostic_transport_error')
                            ->label('Transport Error')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->transport_error_type)
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->transport_error_type))
                            ->badge()
                            ->color(fn (?string $state): string => UptimeTransportError::color($state)),
                    ])
                    ->columns(4),
            ]);
    }
}
