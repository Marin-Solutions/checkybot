<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    public ?string $oneTimePlainTextKey = null;

    public ?string $oneTimeKeyName = null;

    public function mount(): void
    {
        parent::mount();

        $this->oneTimePlainTextKey = session()->pull('api_key_plain_text');
        $this->oneTimeKeyName = session()->pull('api_key_name');
    }

    public function dismissOneTimeKey(): void
    {
        $this->oneTimePlainTextKey = null;
        $this->oneTimeKeyName = null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.resources.api-key-resource.one-time-key-panel')
                    ->visible(fn (): bool => filled($this->oneTimePlainTextKey))
                    ->viewData(fn (): array => [
                        'plainTextKey' => $this->oneTimePlainTextKey,
                        'keyName' => $this->oneTimeKeyName,
                    ]),
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->createAnother(false)
                ->successNotificationTitle(null)
                ->using(function (array $data): ApiKey {
                    $plainTextKey = ApiKey::generateKey();
                    $data['user_id'] = auth()->id();
                    $data['key'] = $plainTextKey;

                    $apiKey = ApiKey::create($data);

                    $this->oneTimePlainTextKey = $plainTextKey;
                    $this->oneTimeKeyName = $apiKey->name;

                    return $apiKey;
                }),
        ];
    }
}
