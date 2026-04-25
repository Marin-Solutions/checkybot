<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use App\Models\MonitorApiResult;
use App\Services\ApiMonitorExecutionService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewMonitorApis extends ViewRecord
{
    protected static string $resource = MonitorApisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_now')
                ->label('Run check now')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-bolt')
                ->modalHeading('Run API monitor now')
                ->modalDescription('Checkybot will execute a real heartbeat against this endpoint immediately and append the result to its run history. Use this when you are triaging an incident and cannot wait for the next scheduled run.')
                ->modalSubmitActionLabel('Run now')
                ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                ->visible(fn (): bool => (bool) $this->record->is_enabled)
                ->action(function (): void {
                    try {
                        /** @var array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null} $outcome */
                        $outcome = app(ApiMonitorExecutionService::class)->execute($this->record);
                    } catch (\Throwable $e) {
                        Log::error('Run Now API monitor check failed', [
                            'monitor_api_id' => $this->record->id,
                            'exception' => $e,
                        ]);

                        Notification::make()
                            ->title('Run failed')
                            ->body('Checkybot could not complete the on-demand check. Check the application logs for details.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->refresh();
                    $this->record->load('latestResult');

                    static::sendRunNowNotification($outcome);
                }),
            Actions\EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonitorApisResource\Widgets\ResponseTimeChart::make(['record' => $this->record]),
        ];
    }

    /**
     * @param  array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null}  $outcome
     */
    protected static function sendRunNowNotification(array $outcome): void
    {
        $result = $outcome['result'];
        $status = $outcome['status'];

        $title = match ($status) {
            'danger' => 'On-demand run failed',
            'warning' => 'On-demand run is degraded',
            default => 'On-demand run succeeded',
        };

        $code = (int) ($result->http_code ?? 0);
        $codeLine = $code > 0 ? "HTTP {$code}" : 'No HTTP response';
        $responseTimeMs = (int) ($result->response_time_ms ?? 0);
        $failedCount = is_array($result->failed_assertions) ? count($result->failed_assertions) : 0;

        $lines = [
            'Recorded to run history.',
            "{$codeLine} • {$responseTimeMs}ms",
        ];

        if ($failedCount > 0) {
            $lines[] = $failedCount === 1
                ? '1 assertion failed.'
                : "{$failedCount} assertions failed.";
        }

        if (filled($outcome['summary'])) {
            $lines[] = $outcome['summary'];
        }

        $body = implode('<br>', array_map(fn (string $line): string => e($line), $lines));

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($status) {
            'danger' => $notification->danger(),
            'warning' => $notification->warning(),
            default => $notification->success(),
        };

        $notification->send();
    }
}
