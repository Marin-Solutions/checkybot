<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use App\Jobs\RunApiMonitorDiagnosticJob;
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
                ->modalDescription('Checkybot will queue a real request against this endpoint, append the result to run history, update live status, and alert subscribers on status changes.')
                ->modalSubmitActionLabel('Run now')
                ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                ->visible(fn (): bool => MonitorApisResource::canRunDiagnostic($this->record))
                ->disabled(fn (): bool => $this->record->hasQueuedDiagnostic())
                ->action(function (): void {
                    $queuedStatePersisted = false;

                    try {
                        $this->record->refresh();

                        if (! MonitorApisResource::canRunDiagnostic($this->record)) {
                            Notification::make()
                                ->title('Diagnostic unavailable')
                                ->body('Archived or disabled API monitors cannot run fresh diagnostics.')
                                ->warning()
                                ->send();

                            return;
                        }

                        if ($this->record->hasQueuedDiagnostic()) {
                            Notification::make()
                                ->title('Diagnostic already queued')
                                ->body('Checkybot is already waiting for this API monitor diagnostic to finish.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $this->record->forceFill([
                            'diagnostic_queued_at' => now(),
                        ])->save();
                        $queuedStatePersisted = true;

                        RunApiMonitorDiagnosticJob::dispatch($this->record->withoutRelations());
                    } catch (\Throwable $e) {
                        if ($queuedStatePersisted) {
                            $this->record->forceFill([
                                'diagnostic_queued_at' => null,
                            ])->save();
                        }

                        Log::error('Run Now API monitor diagnostic dispatch failed', [
                            'monitor_api_id' => $this->record->id,
                            'exception' => $e,
                        ]);

                        Notification::make()
                            ->title('Diagnostic could not be queued')
                            ->body('Checkybot could not queue the on-demand check. Check the application logs for details.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Diagnostic queued')
                        ->body('Checkybot will run this API monitor in the background and add the evidence to diagnostic history.')
                        ->success()
                        ->send();
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
