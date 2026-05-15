<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use App\Models\ServerLogCategory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class LogCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'logCategories';

    protected static ?string $modelLabel = 'Log file Category';

    protected static ?string $title = 'Log File Categories';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('log_directory')
                    ->required()
                    ->maxLength(255),
                \Filament\Schemas\Components\Fieldset::make('Setting')
                    ->schema([
                        Forms\Components\Toggle::make('should_collect')
                            ->required()
                            ->onColor('success')
                            ->default(true),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('files')
                ->with('latestFile'))
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('log_directory'),
                Tables\Columns\ToggleColumn::make('should_collect'),
                Tables\Columns\TextColumn::make('files_count')
                    ->label('Collected Files')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_collected_at')
                    ->label('Last Collected')
                    ->dateTimeInUserZone()
                    ->placeholder('Never')
                    ->sortable(),
                Tables\Columns\TextColumn::make('latestFile.log_file_name')
                    ->label('Latest File')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? basename($state) : null)
                    ->limit(32)
                    ->tooltip(fn (?string $state): ?string => $state ? basename($state) : null)
                    ->placeholder('-'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()->authorize(true),
            ])
            ->actions([
                Action::make('viewLogFiles')
                    ->label('View Files')
                    ->icon('heroicon-m-document-text')
                    ->modalHeading(fn (ServerLogCategory $record): string => "Collected files for {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn (Action $action): Action => $action
                        ->name('closeLogFilesModal')
                        ->label('Close'))
                    ->modalWidth('4xl')
                    ->visible(fn (ServerLogCategory $record): bool => $record->files_count > 0)
                    ->modalContent(fn (ServerLogCategory $record): View => view('filament.resources.server-resource.log-files-modal', [
                        'files' => $record->files()->latest()->limit(50)->get(),
                    ])),
                Action::make('downloadLatestLogFile')
                    ->label('Download Latest')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->url(fn (ServerLogCategory $record): ?string => $record->latestFile
                        ? route('server-log-file-history.download', $record->latestFile)
                        : null)
                    ->visible(fn (ServerLogCategory $record): bool => (bool) $record->latestFile),
                \Filament\Actions\EditAction::make()->authorize(true),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
