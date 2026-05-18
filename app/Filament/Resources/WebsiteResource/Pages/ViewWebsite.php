<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Jobs\LogUptimeSslJob;
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
                ->modalDescription('Checkybot will queue the enabled checks for this website, append the result to run history, update live status, and alert subscribers on status changes.')
                ->modalSubmitActionLabel('Run now')
                ->authorize(fn (): bool => auth()->user()?->can('Update:Website') ?? false)
                ->visible(fn (): bool => WebsiteResource::canRunDiagnostic($this->record))
                ->disabled(fn (): bool => $this->record->hasQueuedDiagnostic())
                ->action(function (): void {
                    $queuedStatePersisted = false;

                    try {
                        $this->record->refresh();

                        if (! WebsiteResource::canRunDiagnostic($this->record)) {
                            Notification::make()
                                ->title('Diagnostic unavailable')
                                ->body('Archived or disabled websites cannot run fresh diagnostics.')
                                ->warning()
                                ->send();

                            return;
                        }

                        if ($this->record->hasQueuedDiagnostic()) {
                            Notification::make()
                                ->title('Diagnostic already queued')
                                ->body('Checkybot is already waiting for this website diagnostic to finish.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $this->record->forceFill([
                            'diagnostic_queued_at' => now(),
                        ])->save();
                        $queuedStatePersisted = true;

                        LogUptimeSslJob::dispatch($this->record->withoutRelations(), onDemand: true);
                    } catch (\Throwable $e) {
                        if ($queuedStatePersisted) {
                            $this->record->forceFill([
                                'diagnostic_queued_at' => null,
                            ])->save();
                        }

                        Log::error('Run Now uptime/SSL diagnostic dispatch failed', [
                            'website_id' => $this->record->id,
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
                        ->body('Checkybot will run this website check in the background and add the evidence to diagnostic history.')
                        ->success()
                        ->send();
                }),
            Actions\EditAction::make(),
        ];
    }
}
