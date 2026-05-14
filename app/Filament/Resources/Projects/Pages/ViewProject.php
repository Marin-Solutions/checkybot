<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\ProjectHealthOverviewWidget;
use App\Filament\Resources\Projects\Widgets\ProjectIncidentFeedWidget;
use App\Models\ApiKey;
use App\Services\CheckybotControlService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public ?string $guidedSetupApiKey = null;

    public ?string $guidedSetupApiKeyName = null;

    public string $guidedSetupSnippet = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->refreshGuidedSetupSnippet();
    }

    public function dismissGuidedSetupApiKey(): void
    {
        $this->guidedSetupApiKey = null;
        $this->guidedSetupApiKeyName = null;

        $this->refreshGuidedSetupSnippet();
    }

    public function issueGuidedSetupApiKey(array $data): ApiKey
    {
        throw_unless(ApiKeyResource::canManageApiKeys(), new HttpException(403));

        $apiKey = ApiKey::issueForUser(auth()->id(), $data);

        $this->guidedSetupApiKey = $apiKey->key;
        $this->guidedSetupApiKeyName = $apiKey->name;
        $this->refreshGuidedSetupSnippet();

        return $apiKey;
    }

    protected function refreshGuidedSetupSnippet(): void
    {
        $this->guidedSetupSnippet = $this->getRecord()->guidedSetupSnippet($this->guidedSetupApiKey);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_diagnostics')
                ->label('Run diagnostics')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-bolt')
                ->modalHeading('Run application diagnostics')
                ->modalDescription('Checkybot will queue all enabled package-managed API and website diagnostics for this application. Results are appended to diagnostic history, so live status and alert notifications stay reserved for scheduled checks.')
                ->modalSubmitActionLabel('Run diagnostics')
                ->authorize(fn (): bool => $this->userCanRunDiagnostics())
                ->action(function (): void {
                    $user = auth()->user();

                    if ($user === null) {
                        abort(403);
                    }

                    try {
                        $owner = $this->record->user()->firstOrFail();
                        $result = app(CheckybotControlService::class)->triggerProjectRun($owner, $this->record->getKey());
                    } catch (\Throwable $e) {
                        Log::error('Project diagnostics dispatch failed from application view action', [
                            'project_id' => $this->record->getKey(),
                            'exception' => $e,
                        ]);

                        Notification::make()
                            ->title('Diagnostics could not be queued')
                            ->body('Checkybot could not queue the application diagnostics. Check the application logs for details.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $queued = (int) ($result['checks_queued'] ?? 0);
                    $skippedAlreadyQueued = (int) ($result['checks_skipped_already_queued'] ?? 0);

                    if ($queued === 0) {
                        if ($skippedAlreadyQueued > 0) {
                            Notification::make()
                                ->title('Diagnostics already queued')
                                ->body("Checkybot skipped {$skippedAlreadyQueued} already queued application ".str('diagnostic')->plural($skippedAlreadyQueued).'.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('No enabled diagnostics')
                            ->body('This application has no enabled package-managed API or website diagnostics to run.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Diagnostics queued')
                        ->body($skippedAlreadyQueued > 0
                            ? "Checkybot queued {$queued} enabled application ".str('diagnostic')->plural($queued)." and skipped {$skippedAlreadyQueued} already queued."
                            : "Checkybot queued {$queued} enabled application ".str('diagnostic')->plural($queued).'.')
                        ->success()
                        ->send();
                }),
            Actions\EditAction::make(),
        ];
    }

    /**
     * The diagnostics action stamps Website and MonitorApis rows directly through
     * the shared control service, so project update permission is not enough.
     */
    protected function userCanRunDiagnostics(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->can('Update:Project')
            && $user->can('Update:Website')
            && $user->can('Update:MonitorApis');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectHealthOverviewWidget::make(['record' => $this->record]),
            ProjectIncidentFeedWidget::make(['record' => $this->record]),
        ];
    }
}
