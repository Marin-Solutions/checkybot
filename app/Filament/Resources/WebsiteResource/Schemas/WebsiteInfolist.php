<?php

namespace App\Filament\Resources\WebsiteResource\Schemas;

use App\Enums\RunSource;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\IntervalParser;
use App\Support\UptimeTransportError;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class WebsiteInfolist
{
    /** @var \WeakMap<Website, array<int, array<string, mixed>>>|null */
    private static ?\WeakMap $recentFailureCache = null;

    /** @var \WeakMap<Website, array{threshold: Carbon|null, invalid_interval: bool}>|null */
    private static ?\WeakMap $expectedStaleCache = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Website')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('url')
                            ->label('URL')
                            ->copyable()
                            ->copyMessage('URL copied'),
                        TextEntry::make('current_status')
                            ->label('Current Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => static::statusColor($state)),
                        TextEntry::make('status_summary')
                            ->label('Latest Summary')
                            ->placeholder('Awaiting first monitor run')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('user.name')
                            ->label('Created By')
                            ->default('-'),
                        TextEntry::make('source')
                            ->label('Managed By')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'package' => 'Package',
                                null, '', 'manual' => 'Manual',
                                default => ucfirst($state),
                            })
                            ->color(fn (?string $state): string => $state === 'package' ? 'info' : 'gray'),
                    ])
                    ->columns(2),
                Section::make('Heartbeat & Freshness')
                    ->description('When Checkybot last heard from this target, when freshness was due, and when stale status was detected.')
                    ->schema([
                        TextEntry::make('last_heartbeat_at')
                            ->label('Last Heartbeat')
                            ->state(fn (Website $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                            ->default('Never')
                            ->hint(fn (Website $record): ?string => $record->last_heartbeat_at?->diffForHumans()),
                        TextEntry::make('expected_stale_at')
                            ->label('Expected Stale Threshold')
                            ->state(fn (Website $record): ?string => static::expectedStaleAt($record)?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (Website $record): ?string => static::expectedStaleHint($record)),
                        TextEntry::make('stale_at')
                            ->label('Detected Stale At')
                            ->state(fn (Website $record): ?string => $record->stale_at?->toDayDateTimeString())
                            ->default('Not detected')
                            ->hint(fn (Website $record): ?string => static::detectedStaleHint($record->stale_at)),
                        TextEntry::make('latest_log_response_time')
                            ->label('Latest Scheduled Response Time')
                            ->state(fn (Website $record): ?string => $record->latestScheduledLogHistory?->speed !== null
                                ? "{$record->latestScheduledLogHistory->speed}ms"
                                : null)
                            ->default('-'),
                        TextEntry::make('average_response_time_24h')
                            ->label('Avg Response (24h)')
                            ->state(function (Website $record): string {
                                $avg = $record->average_response_time;

                                return $avg !== null ? round($avg).'ms' : '-';
                            }),
                    ])
                    ->columns(3),
                Section::make('Uptime Monitoring')
                    ->schema([
                        IconEntry::make('uptime_check')
                            ->label('Uptime Check')
                            ->boolean(),
                        TextEntry::make('uptime_interval')
                            ->label('Check Interval')
                            ->formatStateUsing(fn (?int $state): string => match ($state) {
                                null => '-',
                                1 => 'Every minute',
                                5 => 'Every 5 minutes',
                                10 => 'Every 10 minutes',
                                15 => 'Every 15 minutes',
                                30 => 'Every 30 minutes',
                                60 => 'Every hour',
                                360 => 'Every 6 hours',
                                720 => 'Every 12 hours',
                                1440 => 'Every 24 hours',
                                default => "Every {$state} minutes",
                            }),
                        TextEntry::make('latest_log_http_code')
                            ->label('Latest Scheduled HTTP Code')
                            ->state(fn (Website $record): ?int => $record->latestScheduledLogHistory?->http_status_code)
                            ->default('-')
                            ->badge()
                            ->color(fn (mixed $state): string => static::httpCodeColor(is_numeric($state) ? (int) $state : null)),
                        TextEntry::make('latest_log_status')
                            ->label('Latest Scheduled Result')
                            ->state(fn (Website $record): ?string => $record->latestScheduledLogHistory?->status)
                            ->default('No scheduled runs recorded')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'No scheduled runs recorded')
                            ->color(fn (?string $state): string => static::statusColor($state)),
                        TextEntry::make('latest_log_run_source')
                            ->label('Evidence Source')
                            ->state(fn (Website $record): mixed => $record->latestScheduledLogHistory?->run_source)
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => RunSource::tryCoerce($state)?->label() ?? '-')
                            ->color(fn (mixed $state): string => RunSource::tryCoerce($state)?->color() ?? 'gray'),
                        TextEntry::make('latest_log_summary')
                            ->label('Latest Scheduled Summary')
                            ->state(fn (Website $record): ?string => $record->latestScheduledLogHistory?->summary)
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('latest_log_transport_error')
                            ->label('Latest Scheduled Transport Error')
                            ->state(fn (Website $record): ?string => static::transportErrorEvidence($record->latestScheduledLogHistory))
                            ->default('-')
                            ->badge()
                            ->color(fn (Website $record): string => UptimeTransportError::color($record->latestScheduledLogHistory?->transport_error_type))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Latest Diagnostic Run')
                    ->description('Manual run evidence is separate from scheduler-owned live status, dashboards, and alerts.')
                    ->hidden(fn (Website $record): bool => $record->latestDiagnosticLogHistory === null)
                    ->schema([
                        TextEntry::make('latest_diagnostic_created_at')
                            ->label('Observed At')
                            ->state(fn (Website $record): ?string => $record->latestDiagnosticLogHistory?->created_at?->toDayDateTimeString())
                            ->hint(fn (Website $record): ?string => $record->latestDiagnosticLogHistory?->created_at?->diffForHumans()),
                        TextEntry::make('latest_diagnostic_status')
                            ->label('Result')
                            ->state(fn (Website $record): ?string => $record->latestDiagnosticLogHistory?->status)
                            ->default('Unknown')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                            ->color(fn (?string $state): string => static::statusColor($state)),
                        TextEntry::make('latest_diagnostic_http_code')
                            ->label('HTTP Code')
                            ->state(fn (Website $record): ?int => $record->latestDiagnosticLogHistory?->http_status_code)
                            ->default('-')
                            ->badge()
                            ->color(fn (mixed $state): string => static::httpCodeColor(is_numeric($state) ? (int) $state : null)),
                        TextEntry::make('latest_diagnostic_response_time')
                            ->label('Response Time')
                            ->state(fn (Website $record): ?string => $record->latestDiagnosticLogHistory?->speed !== null
                                ? "{$record->latestDiagnosticLogHistory->speed}ms"
                                : null)
                            ->default('-'),
                        TextEntry::make('latest_diagnostic_summary')
                            ->label('Summary')
                            ->state(fn (Website $record): ?string => $record->latestDiagnosticLogHistory?->summary)
                            ->default('-')
                            ->columnSpanFull(),
                        TextEntry::make('latest_diagnostic_transport_error')
                            ->label('Transport Error')
                            ->state(fn (Website $record): ?string => static::transportErrorEvidence($record->latestDiagnosticLogHistory))
                            ->default('-')
                            ->badge()
                            ->color(fn (Website $record): string => UptimeTransportError::color($record->latestDiagnosticLogHistory?->transport_error_type))
                            ->columnSpanFull(),
                    ])
                    ->columns(4),
                Section::make('SSL Certificate')
                    ->description('Current SSL posture and expiry tracking.')
                    ->schema([
                        IconEntry::make('ssl_check')
                            ->label('SSL Check')
                            ->boolean(),
                        TextEntry::make('ssl_expiry_date')
                            ->label('Expires On')
                            ->state(fn (Website $record): ?string => static::formatSslDate($record->ssl_expiry_date))
                            ->default('Unknown')
                            ->badge()
                            ->color(fn (Website $record): string => static::sslExpiryColor($record->ssl_expiry_date))
                            ->hint(fn (Website $record): ?string => static::sslExpiryHint($record->ssl_expiry_date)),
                    ])
                    ->columns(2),
                Section::make('Outbound Link Check')
                    ->description('Checkybot verifies external links on this website for broken targets.')
                    ->hidden(fn (Website $record): bool => ! $record->outbound_check)
                    ->schema([
                        TextEntry::make('last_outbound_checked_at')
                            ->label('Last Outbound Scan')
                            ->state(fn (Website $record): ?string => $record->last_outbound_checked_at?->toDayDateTimeString())
                            ->default('Never')
                            ->hint(fn (Website $record): ?string => $record->last_outbound_checked_at?->diffForHumans()),
                    ]),
                Section::make('Recent Failures')
                    ->description('Most recent non-healthy monitor runs from the last 7 days.')
                    ->hidden(fn (Website $record): bool => empty(static::recentFailures($record)))
                    ->schema([
                        RepeatableEntry::make('recent_failure_evidence')
                            ->label('')
                            ->state(fn (Website $record): array => static::recentFailures($record))
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                                    ->color(fn (?string $state): string => static::statusColor($state)),
                                TextEntry::make('http_status_code')
                                    ->label('HTTP')
                                    ->badge()
                                    ->color(fn (mixed $state): string => static::httpCodeColor(is_numeric($state) ? (int) $state : null))
                                    ->default('-'),
                                TextEntry::make('speed')
                                    ->label('Response Time'),
                                TextEntry::make('transport_error')
                                    ->label('Transport Error')
                                    ->badge()
                                    ->color(fn (?string $state): string => UptimeTransportError::color($state))
                                    ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                                    ->default('-'),
                                TextEntry::make('created_at')
                                    ->label('Observed At')
                                    ->default('-'),
                                TextEntry::make('transport_evidence')
                                    ->label('Transport Evidence')
                                    ->columnSpanFull()
                                    ->default('-'),
                                TextEntry::make('summary')
                                    ->columnSpanFull()
                                    ->default('-'),
                            ])
                            ->contained(false)
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function recentFailures(Website $record): array
    {
        static::$recentFailureCache ??= new \WeakMap;

        return static::$recentFailureCache[$record] ??= $record->logHistory()
            ->whereIn('status', ['warning', 'danger'])
            ->where('is_on_demand', false)
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (WebsiteLogHistory $log): array => [
                'status' => $log->status,
                'http_status_code' => $log->http_status_code,
                'speed' => $log->speed !== null ? "{$log->speed}ms" : '-',
                'transport_error' => $log->transport_error_type,
                'transport_evidence' => static::transportErrorEvidence($log) ?? '-',
                'summary' => $log->summary ?: '-',
                'created_at' => $log->created_at?->toDayDateTimeString(),
            ])
            ->all();
    }

    private static function transportErrorEvidence(?WebsiteLogHistory $log): ?string
    {
        if (! $log?->transport_error_type) {
            return null;
        }

        $label = UptimeTransportError::label($log->transport_error_type);
        $code = $log->transport_error_code !== null ? " (code {$log->transport_error_code})" : '';
        $message = filled($log->transport_error_message) ? ': '.$log->transport_error_message : '';

        return "{$label}{$code}{$message}";
    }

    private static function statusColor(?string $state): string
    {
        return match ($state) {
            'healthy' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'gray',
        };
    }

    private static function httpCodeColor(?int $code): string
    {
        if ($code === null) {
            return 'gray';
        }

        return match (true) {
            $code <= 0 => 'danger',
            $code >= 200 && $code < 300 => 'success',
            $code >= 300 && $code < 400 => 'info',
            $code >= 400 && $code < 500 => 'warning',
            $code >= 500 => 'danger',
            default => 'danger',
        };
    }

    private static function formatSslDate(mixed $value): ?string
    {
        $date = static::toCarbon($value);

        return $date?->toFormattedDateString();
    }

    private static function sslExpiryColor(mixed $value): string
    {
        $date = static::toCarbon($value);

        if ($date === null) {
            return 'gray';
        }

        $daysRemaining = static::daysUntilExpiry($date);

        return match (true) {
            $daysRemaining < 0 => 'danger',
            $daysRemaining <= 14 => 'warning',
            default => 'success',
        };
    }

    private static function sslExpiryHint(mixed $value): ?string
    {
        $date = static::toCarbon($value);

        if ($date === null) {
            return null;
        }

        $daysRemaining = static::daysUntilExpiry($date);

        if ($daysRemaining === 0) {
            return 'Expires today';
        }

        if ($daysRemaining === 1) {
            return 'Expires tomorrow';
        }

        if ($daysRemaining === -1) {
            return 'Expired yesterday';
        }

        return $daysRemaining < 0
            ? 'Expired '.abs($daysRemaining).' days ago'
            : "Expires in {$daysRemaining} days";
    }

    /**
     * Whole days remaining until the expiry date, comparing dates at the
     * start of day so certificates that expire "today" report 0 days
     * remaining (rather than being flagged as already expired at noon)
     * and the color and hint stay consistent.
     */
    private static function daysUntilExpiry(Carbon $expiryDate): int
    {
        $today = now()->startOfDay();
        $expiry = $expiryDate->copy()->startOfDay();

        return (int) round($today->diffInDays($expiry, false));
    }

    private static function expectedStaleAt(Website $record): ?Carbon
    {
        return static::expectedStaleEvidence($record)['threshold'];
    }

    private static function expectedStaleHint(Website $record): ?string
    {
        $evidence = static::expectedStaleEvidence($record);

        if ($evidence['invalid_interval']) {
            return "Cannot parse package interval {$record->package_interval}";
        }

        if ($evidence['threshold'] === null) {
            return null;
        }

        return $evidence['threshold']->isPast()
            ? 'Freshness threshold passed '.$evidence['threshold']->diffForHumans()
            : 'Freshness threshold '.$evidence['threshold']->diffForHumans();
    }

    /**
     * @return array{threshold: Carbon|null, invalid_interval: bool}
     */
    private static function expectedStaleEvidence(Website $record): array
    {
        static::$expectedStaleCache ??= new \WeakMap;

        return static::$expectedStaleCache[$record] ??= static::resolveExpectedStaleEvidence($record);
    }

    /**
     * @return array{threshold: Carbon|null, invalid_interval: bool}
     */
    private static function resolveExpectedStaleEvidence(Website $record): array
    {
        if ($record->last_heartbeat_at === null || blank($record->package_interval)) {
            return ['threshold' => null, 'invalid_interval' => false];
        }

        try {
            return [
                'threshold' => $record->last_heartbeat_at->copy()->addMinutes(IntervalParser::toMinutes($record->package_interval)),
                'invalid_interval' => false,
            ];
        } catch (\Throwable) {
            return ['threshold' => null, 'invalid_interval' => true];
        }
    }

    private static function detectedStaleHint(?Carbon $staleAt): ?string
    {
        if ($staleAt === null) {
            return null;
        }

        return $staleAt->isPast()
            ? 'Detected stale '.$staleAt->diffForHumans()
            : 'Scheduled detection '.$staleAt->diffForHumans();
    }

    private static function toCarbon(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
