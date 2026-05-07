<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BackupRemoteStorageResource\Pages;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BackupRemoteStorageResource extends Resource
{
    protected static ?string $model = BackupRemoteStorageConfig::class;

    protected static \UnitEnum|string|null $navigationGroup = 'Backup Manager';

    protected static ?int $navigationSort = 18;

    protected static ?string $modelLabel = 'Remote Storage';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static function selectedStorageTypeId(callable $get): mixed
    {
        return $get('backup_remote_storage_type_id');
    }

    protected static function shouldHideFileTransferFields(): \Closure
    {
        return fn (callable $get): bool => ! BackupRemoteStorageType::usesFileTransferFieldsForId(self::selectedStorageTypeId($get));
    }

    protected static function shouldHideS3Fields(): \Closure
    {
        return fn (callable $get): bool => ! BackupRemoteStorageType::usesS3FieldsForId(self::selectedStorageTypeId($get));
    }

    protected static function shouldHideS3EndpointField(): \Closure
    {
        return fn (callable $get): bool => ! BackupRemoteStorageType::requiresEndpointForId(self::selectedStorageTypeId($get));
    }

    protected static function requiredWhenSftpFtp(): \Closure
    {
        return fn (callable $get): bool => BackupRemoteStorageType::usesFileTransferFieldsForId(self::selectedStorageTypeId($get));
    }

    protected static function requiredWhenCustomAwsS3(): \Closure
    {
        return fn (callable $get): bool => BackupRemoteStorageType::usesS3FieldsForId(self::selectedStorageTypeId($get));
    }

    protected static function requiredWhenCustomS3(): \Closure
    {
        return fn (callable $get): bool => BackupRemoteStorageType::requiresEndpointForId(self::selectedStorageTypeId($get));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Connection Setting')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('label')->required(),
                        Forms\Components\Select::make('backup_remote_storage_type_id')
                            ->required()
                            ->label('Type')
                            ->relationship(
                                name: 'storageType',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('flag_active', true)->orderBy('id'),
                            )
                            ->reactive(),
                        /* For FTP or SFTP */
                        Forms\Components\TextInput::make('host')
                            ->required(self::requiredWhenSftpFtp())
                            ->hidden(self::shouldHideFileTransferFields()),
                        Forms\Components\TextInput::make('port')->numeric()->default('21')
                            ->required(self::requiredWhenSftpFtp())
                            ->hidden(self::shouldHideFileTransferFields()),
                        Forms\Components\TextInput::make('username')
                            ->required(self::requiredWhenSftpFtp())
                            ->hidden(self::shouldHideFileTransferFields()),
                        Forms\Components\TextInput::make('password')->password()
                            ->required(self::requiredWhenSftpFtp())
                            ->hidden(self::shouldHideFileTransferFields()),
                        Forms\Components\TextInput::make('directory')
                            ->required(self::requiredWhenSftpFtp())
                            ->hidden(self::shouldHideFileTransferFields()),
                        /* For AWS S3 or Custom S3 */
                        Forms\Components\TextInput::make('access_key')
                            ->required(self::requiredWhenCustomAwsS3())
                            ->hidden(self::shouldHideS3Fields()),
                        Forms\Components\TextInput::make('secret_key')->password()
                            ->required(self::requiredWhenCustomAwsS3())
                            ->hidden(self::shouldHideS3Fields()),
                        Forms\Components\TextInput::make('bucket')
                            ->required(self::requiredWhenCustomAwsS3())
                            ->hidden(self::shouldHideS3Fields()),
                        Forms\Components\TextInput::make('region')
                            ->required(self::requiredWhenCustomAwsS3())
                            ->hidden(self::shouldHideS3Fields()),
                        Forms\Components\TextInput::make('endpoint')
                            ->required(self::requiredWhenCustomS3())
                            ->hidden(self::shouldHideS3EndpointField()),
                    ])
                    ->footerActions([
                        Action::make('test_connection')
                            ->outlined()
                            ->action(function ($state) {
                                $rules = [
                                    'backup_remote_storage_type_id' => 'required',
                                    'host' => [Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesFileTransferFieldsForId($state['backup_remote_storage_type_id'] ?? null)), 'string', 'max:255'],
                                    'port' => 'nullable|integer|min:1|max:65535',
                                    'username' => [Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesFileTransferFieldsForId($state['backup_remote_storage_type_id'] ?? null)), 'string', 'max:255'],
                                    'password' => [Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesFileTransferFieldsForId($state['backup_remote_storage_type_id'] ?? null)), 'string', 'max:255'],
                                    'directory' => [Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesFileTransferFieldsForId($state['backup_remote_storage_type_id'] ?? null)), 'string', 'max:255'],
                                    'access_key' => Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesS3FieldsForId($state['backup_remote_storage_type_id'] ?? null)),
                                    'secret_key' => Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesS3FieldsForId($state['backup_remote_storage_type_id'] ?? null)),
                                    'bucket' => Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesS3FieldsForId($state['backup_remote_storage_type_id'] ?? null)),
                                    'region' => Rule::requiredIf(fn (): bool => BackupRemoteStorageType::usesS3FieldsForId($state['backup_remote_storage_type_id'] ?? null)),
                                    'endpoint' => Rule::requiredIf(fn (): bool => BackupRemoteStorageType::requiresEndpointForId($state['backup_remote_storage_type_id'] ?? null)),
                                ];

                                $validator = Validator::make($state, $rules);

                                if ($validator->fails()) {
                                    return Notification::make()
                                        ->danger()
                                        ->title('Please set up your connection properly')
                                        ->send();
                                } else {
                                    $testConnection = BackupRemoteStorageConfig::testConnection($state);

                                    return Notification::make()
                                        ->{$testConnection['error'] ? 'danger' : 'success'}()
                                        ->title($testConnection['title'])
                                        ->body($testConnection['message'])
                                        ->send();
                                }
                            }),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('storageType.name'),
                Tables\Columns\TextColumn::make('host'),
                Tables\Columns\TextColumn::make('port')->numeric(),
                Tables\Columns\TextColumn::make('username'),
                Tables\Columns\TextColumn::make('access_key'),
                Tables\Columns\TextColumn::make('bucket'),
                Tables\Columns\TextColumn::make('region'),
                Tables\Columns\TextColumn::make('endpoint'),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListBackupRemoteStorages::route('/'),
            'create' => Pages\CreateBackupRemoteStorage::route('/create'),
            'edit' => Pages\EditBackupRemoteStorage::route('/{record}/edit'),
        ];
    }
}
