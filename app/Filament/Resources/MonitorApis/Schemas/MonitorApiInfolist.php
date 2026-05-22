<?php

namespace App\Filament\Resources\MonitorApis\Schemas;

use App\Enums\RunSource;
use App\Models\MonitorApis;
use App\Services\IntervalParser;
use App\Support\ApiMonitorEvidenceFormatter;
use App\Support\HealthStatusLabel;
use App\Support\PackageCheckTableEvidence;
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
                            ->label('Live Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                            ->color(fn (?string $state): string => HealthStatusLabel::color($state)),
                        TextEntry::make('status_summary')
                            ->label('Live Summary')
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
                            ->badge()
                            ->color(fn (MonitorApis $record): string => self::resultExceedsResponseTimeThreshold($record->latestResult) ? 'warning' : 'gray')
                            ->default('-'),
                        TextEntry::make('latest_result_elapsed_wall_time')
                            ->label('Latest Wall Time')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->elapsed_wall_time_ms !== null ? "{$record->latestResult->elapsed_wall_time_ms}ms" : null)
                            ->default('-'),
                        TextEntry::make('package_interval')
                            ->label('Polling Interval')
                            ->state(fn (MonitorApis $record): string => PackageCheckTableEvidence::displayInterval($record->package_interval) ?? 'Missing')
                            ->badge()
                            ->color('gray')
                            ->hint('The scheduler wakes up every minute and runs this monitor when its interval is due.'),
                        TextEntry::make('schedule_due_state')
                            ->label('Schedule State')
                            ->state(fn (MonitorApis $record): string => PackageCheckTableEvidence::dueState($record))
                            ->badge()
                            ->color(fn (string $state): string => PackageCheckTableEvidence::dueStateColor($state)),
                        TextEntry::make('next_scheduled_run')
                            ->label('Next Scheduled Run')
                            ->state(fn (MonitorApis $record): ?string => PackageCheckTableEvidence::nextDueAt($record)?->toDayDateTimeString())
                            ->default('Next scheduler pass')
                            ->hint(fn (MonitorApis $record): string => PackageCheckTableEvidence::dueDescription($record)),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
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
                        TextEntry::make('max_response_time_ms')
                            ->label('Response-time Warning')
                            ->state(fn (MonitorApis $record): ?string => $record->max_response_time_ms !== null ? "{$record->max_response_time_ms}ms" : null)
                            ->default('Not configured')
                            ->badge()
                            ->color(fn (MonitorApis $record): string => $record->max_response_time_ms !== null ? 'warning' : 'gray'),
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
                    ->columns(3)
                    ->columnSpanFull(),
                Section::make('Latest Run Evidence')
                    ->description('This evidence controls the live status, dashboards, and alerts.')
                    ->hidden(fn (MonitorApis $record): bool => $record->latestResult === null)
                    ->schema([
                        TextEntry::make('latest_scheduled_status')
                            ->label('Run Status')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->status)
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                            ->color(fn (?string $state): string => HealthStatusLabel::color($state)),
                        TextEntry::make('latest_scheduled_run_source')
                            ->label('Evidence Source')
                            ->state(fn (MonitorApis $record): mixed => $record->latestResult?->run_source)
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                            ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                        TextEntry::make('latest_scheduled_summary')
                            ->label('Run Summary')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->summary)
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('latest_scheduled_transport_error_type')
                            ->label('Transport Error')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->transport_error_type)
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                            ->color(fn (?string $state): string => UptimeTransportError::color($state))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type)),
                        TextEntry::make('latest_scheduled_transport_error_code')
                            ->label('cURL Error Code')
                            ->state(fn (MonitorApis $record): ?int => $record->latestResult?->transport_error_code)
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type))
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('latest_scheduled_transport_error_message')
                            ->label('Transport Message')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->transport_error_message)
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestResult?->transport_error_type))
                            ->copyable()
                            ->columnSpanFull(),
                        TextEntry::make('latest_scheduled_execution_timeout')
                            ->label('Effective Timeout')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->effective_timeout_seconds !== null ? "{$record->latestResult->effective_timeout_seconds}s" : null)
                            ->default('-'),
                        TextEntry::make('latest_scheduled_retry_count')
                            ->label('Retries')
                            ->state(fn (MonitorApis $record): ?int => $record->latestResult?->retry_count)
                            ->default('-'),
                        TextEntry::make('latest_scheduled_elapsed_wall_time')
                            ->label('Elapsed Wall Time')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->elapsed_wall_time_ms !== null ? "{$record->latestResult->elapsed_wall_time_ms}ms" : null)
                            ->default('-'),
                        TextEntry::make('latest_scheduled_max_response_time')
                            ->label('Response-time Warning')
                            ->state(fn (MonitorApis $record): ?string => $record->latestResult?->max_response_time_ms !== null ? "{$record->latestResult->max_response_time_ms}ms" : null)
                            ->default('Not configured')
                            ->badge()
                            ->color(fn (MonitorApis $record): string => self::resultExceedsResponseTimeThreshold($record->latestResult) ? 'warning' : 'gray'),
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
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Latest Manual Run')
                    ->description('Manual runs use the same live status and alerting path as scheduled runs.')
                    ->hidden(fn (MonitorApis $record): bool => $record->latestDiagnosticResult === null && ! $record->hasQueuedDiagnostic())
                    ->schema([
                        TextEntry::make('diagnostic_queue_status')
                            ->label('Manual Run Status')
                            ->state(fn (MonitorApis $record): ?string => $record->hasQueuedDiagnostic() ? 'Queued' : null)
                            ->hidden(fn (MonitorApis $record): bool => ! $record->hasQueuedDiagnostic())
                            ->badge()
                            ->color('warning'),
                        TextEntry::make('diagnostic_queued_at')
                            ->label('Queued At')
                            ->state(fn (MonitorApis $record): ?string => $record->diagnostic_queued_at?->toDayDateTimeString())
                            ->hint(fn (MonitorApis $record): ?string => $record->diagnostic_queued_at?->diffForHumans())
                            ->hidden(fn (MonitorApis $record): bool => ! $record->hasQueuedDiagnostic()),
                        TextEntry::make('latest_diagnostic_created_at')
                            ->label('Observed At')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->created_at?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->created_at?->diffForHumans()),
                        TextEntry::make('latest_diagnostic_status')
                            ->label('Result')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->status)
                            ->default('Unknown')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                            ->color(fn (?string $state): string => HealthStatusLabel::color($state)),
                        TextEntry::make('latest_diagnostic_live_status_note')
                            ->label('Live Status Impact')
                            ->state(fn (MonitorApis $record): string => self::diagnosticLiveStatusNote($record))
                            ->columnSpanFull(),
                        TextEntry::make('latest_diagnostic_freshness_note')
                            ->label('Freshness')
                            ->state(fn (MonitorApis $record): ?string => self::diagnosticFreshnessNote($record))
                            ->hidden(fn (MonitorApis $record): bool => self::diagnosticFreshnessNote($record) === null)
                            ->badge()
                            ->color('warning')
                            ->columnSpanFull(),
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
                            ->badge()
                            ->color(fn (MonitorApis $record): string => self::resultExceedsResponseTimeThreshold($record->latestDiagnosticResult) ? 'warning' : 'gray')
                            ->default('-'),
                        TextEntry::make('latest_diagnostic_elapsed_wall_time')
                            ->label('Elapsed Wall Time')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->elapsed_wall_time_ms !== null ? "{$record->latestDiagnosticResult->elapsed_wall_time_ms}ms" : null)
                            ->default('-'),
                        TextEntry::make('latest_diagnostic_execution_timeout')
                            ->label('Effective Timeout')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->effective_timeout_seconds !== null ? "{$record->latestDiagnosticResult->effective_timeout_seconds}s" : null)
                            ->default('-'),
                        TextEntry::make('latest_diagnostic_max_response_time')
                            ->label('Response-time Warning')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->max_response_time_ms !== null ? "{$record->latestDiagnosticResult->max_response_time_ms}ms" : null)
                            ->default('Not configured')
                            ->badge()
                            ->color(fn (MonitorApis $record): string => self::resultExceedsResponseTimeThreshold($record->latestDiagnosticResult) ? 'warning' : 'gray'),
                        TextEntry::make('latest_diagnostic_retry_count')
                            ->label('Retries')
                            ->state(fn (MonitorApis $record): ?int => $record->latestDiagnosticResult?->retry_count)
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
                        TextEntry::make('latest_diagnostic_transport_error_code')
                            ->label('cURL Error Code')
                            ->state(fn (MonitorApis $record): ?int => $record->latestDiagnosticResult?->transport_error_code)
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->transport_error_type))
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('latest_diagnostic_transport_error_message')
                            ->label('Transport Message')
                            ->state(fn (MonitorApis $record): ?string => $record->latestDiagnosticResult?->transport_error_message)
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->transport_error_type))
                            ->copyable()
                            ->columnSpanFull(),
                        RepeatableEntry::make('latest_diagnostic_failed_assertions')
                            ->label('Failed Assertions')
                            ->state(fn (MonitorApis $record): array => ApiMonitorEvidenceFormatter::normalizeAssertions($record->latestDiagnosticResult?->failed_assertions))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->failed_assertions))
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
                            ->columns(2)
                            ->columnSpanFull(),
                        KeyValueEntry::make('latest_diagnostic_request_headers')
                            ->label('Request Headers Snapshot')
                            ->state(fn (MonitorApis $record): array => $record->latestDiagnosticResult?->request_headers ?? [])
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->request_headers)),
                        KeyValueEntry::make('latest_diagnostic_response_headers')
                            ->label('Response Headers Snapshot')
                            ->state(fn (MonitorApis $record): array => $record->latestDiagnosticResult?->response_headers ?? [])
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->response_headers)),
                        TextEntry::make('latest_diagnostic_response_body')
                            ->label('Saved Failure Payload')
                            ->state(fn (MonitorApis $record): string => ApiMonitorEvidenceFormatter::formatPayload($record->latestDiagnosticResult?->response_body))
                            ->hidden(fn (MonitorApis $record): bool => blank($record->latestDiagnosticResult?->response_body))
                            ->html()
                            ->formatStateUsing(fn (string $state) => ApiMonitorEvidenceFormatter::formatAsPreHtml($state))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function diagnosticLiveStatusNote(MonitorApis $record): string
    {
        $diagnosticStatus = $record->latestDiagnosticResult?->status;
        $liveStatus = $record->current_status;

        if (filled($diagnosticStatus) && filled($liveStatus) && $diagnosticStatus !== $liveStatus) {
            return sprintf(
                'Manual run is %s, but the current live status is %s.',
                ucfirst($diagnosticStatus),
                ucfirst($liveStatus),
            );
        }

        return 'This manual run is saved in history and updates live status.';
    }

    private static function diagnosticFreshnessNote(MonitorApis $record): ?string
    {
        $diagnosticAt = $record->latestDiagnosticResult?->created_at;

        if ($diagnosticAt === null) {
            return null;
        }

        $scheduledAt = $record->latestScheduledResult?->created_at;

        if ($scheduledAt !== null && $diagnosticAt->lt($scheduledAt)) {
            return 'Manual evidence is stale: '.$diagnosticAt->diffForHumans($scheduledAt, true).' older than the latest scheduled run.';
        }

        $ageSeconds = max(0, $diagnosticAt->diffInSeconds(now(), false));

        if ($ageSeconds > self::freshnessWindowSeconds($record->package_interval ?? $record->package_schedule ?? null)) {
            return 'Manual evidence is stale: '.$diagnosticAt->diffForHumans(null, true).' old.';
        }

        return null;
    }

    private static function freshnessWindowSeconds(?string $interval): int
    {
        if (is_string($interval) && filled($interval) && IntervalParser::isValid($interval)) {
            return max(IntervalParser::toMinutes($interval) * 60, 60 * 60);
        }

        return 60 * 60;
    }

    private static function resultExceedsResponseTimeThreshold(mixed $result): bool
    {
        return $result?->response_time_ms !== null
            && $result?->max_response_time_ms !== null
            && $result->response_time_ms > $result->max_response_time_ms;
    }
}
