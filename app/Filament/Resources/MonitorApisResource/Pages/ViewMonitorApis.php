<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use App\Services\ApiMonitorExecutionService;
use App\Support\ApiMonitorRunNotification;
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
                ->modalDescription('Checkybot will execute a real heartbeat against this endpoint immediately and append the result to its run history. The monitor\'s live status is reserved for the scheduler, so this manual run will not move the dashboard or alert subscribers. Use this when you are triaging an incident and cannot wait for the next scheduled run.')
                ->modalSubmitActionLabel('Run now')
                ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                ->visible(fn (): bool => (bool) $this->record->is_enabled)
                ->action(function (): void {
                    try {
                        /** @var array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null} $outcome */
                        $outcome = app(ApiMonitorExecutionService::class)->execute($this->record, onDemand: true);
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

                    $this->record->load(['latestResult', 'latestScheduledResult', 'latestDiagnosticResult']);

                    ApiMonitorRunNotification::send($outcome);
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
}
