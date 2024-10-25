<?php

    namespace App\Filament\Resources\BackupRemoteStorageResource\Pages;

    use App\Filament\Resources\BackupRemoteStorageResource;
    use App\Models\BackupRemoteStorageConfig;
    use Filament\Actions;
    use Filament\Notifications\Notification;
    use Filament\Resources\Pages\CreateRecord;
    use Illuminate\Database\Eloquent\Model;

    class CreateBackupRemoteStorage extends CreateRecord
    {
        protected static string $resource = BackupRemoteStorageResource::class;

        protected function mutateFormDataBeforeCreate( array $data ): array
        {
            switch ( $data[ 'backup_remote_storage_type_id' ] ) {
                case '1':
                case '2':
                    unset($data[ 'access_key' ], $data[ 'secret_key' ], $data[ 'bucket' ], $data[ 'region' ], $data[ 'endpoint' ]);
                    break;
                case '3':
                    unset($data[ 'host' ], $data[ 'port' ], $data[ 'username' ], $data[ 'password' ], $data[ 'directory' ], $data[ 'endpoint' ]);
                    break;
                case '4':
                    unset($data[ 'host' ], $data[ 'port' ], $data[ 'username' ], $data[ 'password' ], $data[ 'directory' ]);
                    break;
            }

            return $data;
        }

        protected function getRedirectUrl(): string
        {
            return $this->previousUrl ?? $this->getResource()::getUrl('index');
        }

        public function beforeCreate(): void
        {
            $testConnection = BackupRemoteStorageConfig::testConnection($this->data);

            if ($testConnection['error']) {
                Notification::make()
                    ->{$testConnection[ 'error' ] ? 'danger' : 'success'}()
                    ->title($testConnection[ 'title' ])
                    ->body($testConnection[ 'message' ])
                    ->send()
                ;

                $this->halt();
            }
        }
    }
