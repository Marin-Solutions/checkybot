<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\ProjectHealthOverviewWidget;
use App\Filament\Resources\Projects\Widgets\ProjectIncidentFeedWidget;
use App\Models\ApiKey;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
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
            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectHealthOverviewWidget::make(['record' => $this->record]),
            ProjectIncidentFeedWidget::make(['record' => $this->record]),
        ];
    }
}
