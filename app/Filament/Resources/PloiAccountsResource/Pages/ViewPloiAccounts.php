<?php

namespace App\Filament\Resources\PloiAccountsResource\Pages;

use App\Filament\Resources\PloiAccountsResource;
use App\Models\PloiAccounts;
use App\Traits\HandlesPloiVerificationNotification;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewPloiAccounts extends ViewRecord
{
    use HandlesPloiVerificationNotification;

    protected static string $resource = PloiAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('verify')
                ->action(function (PloiAccounts $record) {
                    $service = new \App\Services\PloiApiService;
                    $result = $service->verifyKey($record->key);
                    $record->update($result);
                    static::notifyPloiVerificationResult($result);
                })
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->hidden(fn ($record) => $record->is_verified),
            Actions\Action::make('back')->url(PloiAccountsResource::getUrl('index'))->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Infolists\Components\TextEntry::make('label'),
                \Filament\Infolists\Components\TextEntry::make('key')
                    ->label('API Key')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 8).'...')
                    ->copyable()
                    ->copyMessage('API Key copied')
                    ->copyMessageDuration(1500),
                \Filament\Infolists\Components\IconEntry::make('is_verified')
                    ->label('Verified')
                    ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
                \Filament\Infolists\Components\TextEntry::make('error_message')
                    ->label('Message')
                    ->hidden(fn ($record) => is_null($record->error_message)),
            ]);
    }
}
