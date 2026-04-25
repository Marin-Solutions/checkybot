<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Jobs\LogUptimeSslJob;
use App\Models\WebsiteLogHistory;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
                ->modalHeading('Run uptime + SSL check now')
                ->modalDescription('Checkybot will run the same heartbeat job that runs on the schedule against this website right now and append the result to its log history. Use this when you are triaging an incident and cannot wait for the next scheduled run.')
                ->modalSubmitActionLabel('Run now')
                ->visible(fn (): bool => (bool) $this->record->uptime_check)
                ->action(function (): void {
                    try {
                        LogUptimeSslJob::dispatchSync($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Run failed')
                            ->body('Checkybot could not complete the on-demand check: '.$e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->refresh();

                    $latestLog = $this->record->logHistory()
                        ->latest('created_at')
                        ->first();

                    static::sendRunNowNotification($this->record->current_status, $latestLog);
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
            $code = (int) ($log->http_status_code ?? 0);
            $codeLine = $code > 0 ? "HTTP {$code}" : 'No HTTP response';
            $speed = (int) ($log->speed ?? 0);
            $lines[] = "{$codeLine} • {$speed}ms";

            if (filled($log->summary)) {
                $lines[] = (string) $log->summary;
            }
        }

        $body = implode('<br>', array_map(fn (string $line): string => e($line), $lines));

        $notification = Notification::make()
            ->title(__($title))
            ->body($body);

        match ($status) {
            'danger' => $notification->danger(),
            'warning' => $notification->warning(),
            'healthy' => $notification->success(),
            default => $notification->success(),
        };

        $notification->send();
    }
}
