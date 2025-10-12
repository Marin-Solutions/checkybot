<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BackupsResource\Pages;
use App\Models\Backup;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;
use Webbingbrasil\FilamentCopyActions\Tables\Actions\CopyAction;

class BackupsResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Manager';

    protected static ?int $navigationSort = 19;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-up';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Fieldset::make()
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('server_id')
                            ->relationship('server', 'name')->required()
                            ->columnSpanFull(),
                        Forms\Components\Select::make('remote_storage_id')
                            ->relationship('remoteStorage', 'label')->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('dir_path')->required()
                            ->label('Directory path')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Fieldset::make()
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('remote_storage_path')
                            ->label('Path on remote storage')->default('/')->required()
                            ->helperText('Define a path here where the backup should be moved to on your remote driver')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('interval_id')
                            ->label('Interval')->relationship('interval', 'label')->required()
                            ->columnSpanFull(),
                        Forms\Components\DateTimePicker::make('first_run_at')
                            ->label('Start time')->helperText("After the first run, it'll follows the original")
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('max_amount_backups')->numeric()->minValue(0)
                            ->default(0)->helperText('Maximum latest backup to keep')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('exclude_folder_files')
                            ->helperText('You can exclude folders inside the path your backing up here, comma separate them to have multiple.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('backup_filename')->label('Custom backup filename (optional)')
                            ->placeholder('backup-file.zip')
                            ->columnSpanFull(),
                        Forms\Components\Radio::make('compression_type')->options(['zip' => 'ZIP', 'tar' => 'TAR'])
                            ->inline()->inlineLabel(false)
                            ->helperText('Note that selecting TAR as compression might result in a different size of your backups')
                            ->reactive()
                            ->columnSpanFull(),
                        Forms\Components\Checkbox::make('delete_local_on_fail')->label('Delete local backup file when backup fail')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Fieldset::make()
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('password')->password()->revealable()
                            ->label('ZIP password')
                            ->disabled(function (callable $get) {
                                return $get('compression_type') === 'tar';
                            })
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('confirm_password')->password()->revealable()
                            ->label('Confirm password')
                            ->disabled(function (callable $get) {
                                return $get('compression_type') === 'tar';
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('dir_path')->label('Folder'),
                Tables\Columns\TextColumn::make('server.name')
                    ->description(fn(Backup $record) => $record->server->ip),
                Tables\Columns\TextColumn::make('remoteStorage.label')
                    ->description(fn(Backup $record) => $record->remoteStorage->host),
                Tables\Columns\TextColumn::make('first_run_at')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                CopyAction::make()
                    ->label('Copy Backup Script')
                    ->copyable(fn(Backup $record) => $record->copyCommand($record)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBackups::route('/'),
            'create' => Pages\CreateBackups::route('/create'),
            'edit' => Pages\EditBackups::route('/{record}/edit'),
        ];
    }
}
