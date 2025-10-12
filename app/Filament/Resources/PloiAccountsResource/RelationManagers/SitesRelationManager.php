<?php

namespace App\Filament\Resources\PloiAccountsResource\RelationManagers;

use App\Models\Website;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('domain')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('domain')
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('server.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('php_version')
                    ->label('PHP Version')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('site_created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('checkybot_website_exists')->exists('checkybotWebsite')
                    ->label('Added to Checkybot')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('import_all_site')
                    ->label('Import All Sites')
                    ->action(function () {
                        if ($this->getOwnerRecord()->servers()->count() === 0) {
                            \Filament\Notifications\Notification::make()
                                ->title('No Servers Available')
                                ->body('You need to import servers first before importing sites. You can do this by clicking the "Import Server" button.')
                                ->warning()
                                ->persistent()
                                ->send();
                        } else {
                            try {
                                $service = new \App\Services\PloiSiteImportService($this->getOwnerRecord());
                                $imported = $service->import();

                                \Filament\Notifications\Notification::make()
                                    ->title('Import complete')
                                    ->body("Imported/updated {$imported} sites.")
                                    ->success()
                                    ->persistent()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Import failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        }
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-globe-alt'),
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                Action::make('add_to_checkybot')
                    ->fillForm(function (Model $record): array {
                        if ($record->checkybot_website_exists) {
                            return $record->checkybotWebsite->toArray();
                        }

                        return [
                            'uptime_check' => false,
                            'ssl_check' => true,
                            'outbound_check' => false,
                        ];
                    })
                    ->form([
                        Fieldset::make('Uptime setting')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Toggle::make('uptime_check')
                                    ->translateLabel()
                                    ->onColor('success')
                                    ->inline(false)
                                    ->columnSpan('1')
                                    ->live()
                                    ->required(),
                                Forms\Components\Select::make('uptime_interval')
                                    ->options([
                                        1 => 'Every minute',
                                        5 => 'Every 5 minutes',
                                        10 => 'Every 10 minutes',
                                        15 => 'Every 15 minutes',
                                        30 => 'Every 30 minutes',
                                        60 => 'Every hour',
                                        360 => 'Every 6 hours',
                                        720 => 'Every 12 hours',
                                        1440 => 'Every 24 hours',
                                    ])
                                    ->translateLabel()
                                    ->required(fn(Forms\Get $get): bool => $get('uptime_check')),
                            ]),
                        Grid::make()
                            ->columns([
                                'default' => 1,
                                'sm' => 2,
                            ])
                            ->schema([
                                Fieldset::make('SSL setting')
                                    ->schema([
                                        Forms\Components\Toggle::make('ssl_check')
                                            ->translateLabel()
                                            ->onColor('success')
                                            ->inline(false)
                                            ->live()
                                            ->default(1)
                                            ->required(),
                                    ])->columnSpan(1),
                                Fieldset::make('Outbound setting')
                                    ->schema([
                                        Forms\Components\Toggle::make('outbound_check')
                                            ->translateLabel()
                                            ->onColor('success')
                                            ->inline(false)
                                            ->live()
                                            ->required(),
                                    ])->columnSpan(1),
                            ]),
                    ])
                    ->before(function (Action $action, Model $record) {
                        \App\Services\WebsiteUrlValidator::validate(
                            'https://' . $record->domain,
                            fn() => $action->halt()
                        );
                    })
                    ->mutateFormDataUsing(function (array $data, Model $record, Action $action): array {
                        try {
                            $data['name'] = $record->server->name . '_' . $record->domain;
                            $data['url'] = 'https://' . $record->domain;
                            $data['description'] = 'Imported from Ploi';
                            $data['ploi_website_id'] = $record->id;

                            $sslExpiryDate = Website::sslExpiryDate('https://' . $record->domain);
                            $data['ssl_expiry_date'] = $sslExpiryDate;
                            $data['created_by'] = auth()->id();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('SSL Expiry Date Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }

                        return $data;
                    })
                    ->action(function (Model $record, array $data) {
                        try {
                            if (Website::where('ploi_website_id', $record->id)->exists()) {
                                throw new \Exception('This site has already been added to Checkybot.');
                            }

                            $website = Website::create($data);

                            if (! $website) {
                                throw new \Exception('Failed to create website record.');
                            }

                            \Filament\Notifications\Notification::make()
                                ->title('Website Created')
                                ->body('Website created successfully and added to Checkybot.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Failed to create website')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->icon('heroicon-o-presentation-chart-line')
                    ->disabled(
                        fn(Model $record) => $record->checkybot_website_exists
                    ),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateDescription(
                'No sites found for this account. You can import existing sites from Ploi.'
            );
    }
}
