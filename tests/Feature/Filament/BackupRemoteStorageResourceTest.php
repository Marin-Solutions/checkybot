<?php

use App\Filament\Resources\BackupRemoteStorageResource\Pages\CreateBackupRemoteStorage;
use App\Models\BackupRemoteStorageConfig;
use Database\Seeders\BackupRemoteStorageTypeOptionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('s3 secret key and region fields use usable input types', function () {
    $this->seed(BackupRemoteStorageTypeOptionsSeeder::class);
    $this->createResourcePermissions('BackupRemoteStorageConfig');
    $this->actingAsSuperAdmin();

    $component = Livewire::test(CreateBackupRemoteStorage::class)
        ->fillForm([
            'backup_remote_storage_type_id' => '3',
        ])
        ->instance();

    $fields = $component->getSchema('form')->getFlatFields();

    expect($fields['secret_key']->isNumeric())->toBeFalse()
        ->and($fields['secret_key']->getDefaultState())->toBeNull()
        ->and($fields['secret_key']->isPassword())->toBeTrue()
        ->and($fields['region']->isPassword())->toBeFalse();
});

test('remote storage connection test uses the configured port', function () {
    $this->seed(BackupRemoteStorageTypeOptionsSeeder::class);

    Storage::shouldReceive('forgetDisk')
        ->once()
        ->with('temp_storage');
    Storage::shouldReceive('disk')
        ->once()
        ->with('temp_storage')
        ->andReturn(new class
        {
            public function exists(string $path): bool
            {
                return $path === '/';
            }
        });

    $result = BackupRemoteStorageConfig::testConnection([
        'backup_remote_storage_type_id' => '1',
        'host' => 'storage.example.com',
        'port' => '2222',
        'username' => 'deploy',
        'password' => 'secret',
        'directory' => '/',
    ]);

    expect($result['error'])->toBeFalse()
        ->and(config('filesystems.disks.temp_storage.port'))->toBe(2222);
});
