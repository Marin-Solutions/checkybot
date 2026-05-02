<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Jobs\LogUptimeSslJob;
use App\Models\WebsiteLogHistory;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewWebsite extends ViewRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_now')
                ->label('Run check now')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-bolt')
                ->modalHeading('Run website diagnostics now')
                ->modalDescription('Checkybot will run the enabled diagnostics for this website right now and append the result to its log history. The website\'s live status is reserved for the scheduler, so this manual run will not move the dashboard or alert subscribers. Use this when you are triaging an incident and cannot wait for the next scheduled run.')
                ->modalSubmitActionLabel('Run now')
                ->authorize(fn (): bool => auth()->user()?->can('Update:Website') ?? false)
                ->visible(fn (): bool => (bool) $this->record->uptime_check || (bool) $this->record->ssl_check)
                ->action(function (): void {
                    try {
                        LogUptimeSslJob::dispatchSync($this->record, onDemand: true);
                    } catch (\Throwable $e) {
                        Log::error('Run Now uptime/SSL check failed', [
                            'website_id' => $this->record->id,
                            'exception' => $e,
                        ]);

                        Notification::make()
                            ->title('Run failed')
                            ->body('Checkybot could not complete the on-demand check. Check the application logs for details.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $latestLog = $this->record->logHistory()
                        ->latest('created_at')
                        ->latest('id')
                        ->first();

                    $this->record->load(['latestLogHistory', 'latestScheduledLogHistory', 'latestDiagnosticLogHistory']);

                    static::sendRunNowNotification($latestLog?->status, $latestLog);
                }),
            Actions\EditAction::make(),
        ];
    }

    protected static function sendRunNowNotification(?string $status, ?WebsiteLogHistory $log): void
    {
        $title = match ($status) {
            'danger' => 'On-demand check failed',
            'warning' => 'On-demand check is degraded',
            'healthy' => 'On-demand check succeeded',
            default => 'On-demand check completed',
        };

        $lines = ['Recorded to log history.'];

        if ($log !== null) {
            $lines[] = static::formatRunNowEvidenceLine($log);

            if (filled($log->summary)) {
                $lines[] = (string) $log->summary;
            }
        }

        $body = implode('<br>', array_map(fn (string $line): string => e($line), $lines));

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($status) {
            'danger' => $notification->danger(),
            'warning' => $notification->warning(),
            'healthy' => $notification->success(),
            default => $notification->success(),
        };

        $notification->send();
    }

    private static function formatRunNowEvidenceLine(WebsiteLogHistory $log): string
    {
        $code = (int) ($log->http_status_code ?? 0);
        $codeLine = match (true) {
            $log->http_status_code === null && $log->ssl_expiry_date !== null => 'SSL certificate checked',
            $log->http_status_code === null && $log->ssl_expiry_date === null => 'SSL certificate check completed',
            $code > 0 => "HTTP {$code}",
            default => 'No HTTP response',
        };

        return $log->speed === null
            ? $codeLine
            : "{$codeLine} • ".((int) $log->speed).'ms';
    }
}
