<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\OutboundLink;
use App\Support\UptimeTransportError;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class OutboundLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'outboundLinks';

    protected static ?string $title = 'Outbound Links';

    protected static ?string $recordTitleAttribute = 'outgoing_url';

    public function table(Table $table): Table
    {
        $safeUrl = fn (?string $state): ?string => $state && preg_match('#^https?://#i', $state) ? $state : null;

        return $table
            ->columns([
                TextColumn::make('found_on')
                    ->label('Found On')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->url($safeUrl)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->wrap(),
                TextColumn::make('outgoing_url')
                    ->label('Outgoing URL')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->url($safeUrl)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->wrap(),
                TextColumn::make('http_status_code')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 300 => 'info',
                        $state >= 200 => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? (string) $state : '—')
                    ->sortable(),
                TextColumn::make('transport_error_type')
                    ->label('Error')
                    ->badge()
                    ->color(fn (?string $state): string => UptimeTransportError::color($state))
                    ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('transport_error_message')
                    ->label('Error Message')
                    ->limit(70)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('last_checked_at')
                    ->label('Last Checked')
                    ->sinceInUserZone()
                    ->tooltip(fn (?OutboundLink $record): ?string => $record?->last_checked_at?->toDayDateTimeString())
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('http_status_code')
                    ->label('Triage status')
                    ->options([
                        'attention' => 'Needs triage',
                        'broken' => 'Broken (4xx & 5xx)',
                        'client_error' => 'Client errors (4xx)',
                        'server_error' => 'Server errors (5xx)',
                        'redirect' => 'Redirects (3xx)',
                        'transport_failed' => 'Transport failed',
                        'success' => 'Successful (2xx)',
                        'unknown' => 'No response recorded',
                        '404' => '404 Not Found',
                        '500' => '500 Internal Server Error',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'attention' => $query->where(function (Builder $query): void {
                                $query
                                    ->whereBetween('http_status_code', [300, 599])
                                    ->orWhereNull('http_status_code')
                                    ->orWhereNotNull('transport_error_type');
                            }),
                            'broken' => $query->whereBetween('http_status_code', [400, 599]),
                            'client_error' => $query->whereBetween('http_status_code', [400, 499]),
                            'server_error' => $query->whereBetween('http_status_code', [500, 599]),
                            'redirect' => $query->whereBetween('http_status_code', [300, 399]),
                            'transport_failed' => $query->whereNotNull('transport_error_type'),
                            'success' => $query->whereBetween('http_status_code', [200, 299]),
                            'unknown' => $query->whereNull('http_status_code'),
                            '404', '500' => $query->where('http_status_code', (int) $value),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort(
                fn (Builder $query): Builder => $query
                    ->orderByRaw(<<<'SQL'
                        CASE
                            WHEN transport_error_type IS NOT NULL THEN 0
                            WHEN http_status_code BETWEEN 400 AND 599 THEN 1
                            WHEN http_status_code BETWEEN 300 AND 399 THEN 2
                            WHEN http_status_code IS NULL THEN 3
                            ELSE 4
                        END
                    SQL)
                    ->orderByDesc('last_checked_at')
            )
            ->defaultSortOptionLabel('Needs triage first')
            ->headerActions([
                Action::make('run_outbound_scan')
                    ->label('Run outbound scan now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalHeading('Run outbound scan now')
                    ->modalDescription('Checkybot will queue a fresh outbound link scan for this website. Use this after fixing broken links to refresh the evidence without waiting for the daily scheduler.')
                    ->modalSubmitActionLabel('Queue scan')
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->ownerRecord) ?? false)
                    ->visible(fn (): bool => (bool) $this->ownerRecord->outbound_check)
                    ->disabled(fn (): bool => $this->ownerRecord->hasQueuedOutboundScan())
                    ->action(function (): void {
                        $queuedStatePersisted = false;
                        $dispatchFailed = false;

                        $this->ownerRecord->refresh();

                        if ($this->ownerRecord->hasQueuedOutboundScan()) {
                            Notification::make()
                                ->title('Outbound scan already queued')
                                ->body('Checkybot is already waiting for this website\'s outbound link scan to finish.')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            $this->ownerRecord->forceFill([
                                'outbound_scan_queued_at' => now(),
                            ])->save();
                            $queuedStatePersisted = true;

                            WebsiteCheckOutboundLinkJob::dispatch($this->ownerRecord, WebsiteCheckOutboundLinkJob::SOURCE_ON_DEMAND)->onQueue('log-website');
                        } catch (\Throwable $e) {
                            $dispatchFailed = true;

                            if ($queuedStatePersisted) {
                                $this->ownerRecord->forceFill([
                                    'outbound_scan_queued_at' => null,
                                ])->save();
                            }

                            Log::error('Outbound scan dispatch failed from relation manager action', [
                                'website_id' => $this->ownerRecord->id,
                                'exception' => $e,
                            ]);
                        }

                        if ($dispatchFailed) {
                            Notification::make()
                                ->title('Outbound scan could not be queued')
                                ->body('Checkybot could not queue the outbound link scan. Check the application logs for details.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Outbound scan queued')
                            ->body('Checkybot will refresh this website\'s outbound link evidence shortly.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No outbound links recorded yet')
            ->emptyStateDescription('Enable the outbound check on this website to start tracking external links and their HTTP status codes.');
    }
}
