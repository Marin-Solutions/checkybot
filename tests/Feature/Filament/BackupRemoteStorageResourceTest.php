<?php

use App\Filament\Resources\BackupRemoteStorageResource\Pages\CreateBackupRemoteStorage;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use Database\Seeders\BackupRemoteStorageTypeOptionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('s3 secret key and region fields use usable input types', function () {
    $this->seed(BackupRemoteStorageTypeOptionsSeeder::class);
    $this->createResourcePermissions('BackupRemoteStorageConfig');
    $this->actingAsSuperAdmin();
    $awsS3Type = BackupRemoteStorageType::query()->where('name', 'AWS S3')->firstOrFail();

    $component = Livewire::test(CreateBackupRemoteStorage::class)
        ->fillForm([
            'backup_remote_storage_type_id' => (string) $awsS3Type->id,
        ])
        ->instance();

    $fields = $component->getSchema('form')->getFlatFields();

    expect($fields['secret_key']->isNumeric())->toBeFalse()
        ->and($fields['secret_key']->getDefaultState())->toBeNull()
        ->and($fields['secret_key']->isPassword())->toBeTrue()
        ->and($fields['region']->isPassword())->toBeFalse();
});

test('remote storage setup field visibility resolves from type driver instead of seeded ids', function () {
    BackupRemoteStorageType::query()->forceCreate([
        'name' => 'Legacy inactive storage',
        'driver' => 'legacy',
        'flag_active' => false,
    ]);

    $this->seed(BackupRemoteStorageTypeOptionsSeeder::class);
    $this->createResourcePermissions('BackupRemoteStorageConfig');
    $this->actingAsSuperAdmin();

    $ftpType = BackupRemoteStorageType::query()->where('driver', BackupRemoteStorageType::DRIVER_FTP)->firstOrFail();
    $awsS3Type = BackupRemoteStorageType::query()->where('name', 'AWS S3')->firstOrFail();
    $customS3Type = BackupRemoteStorageType::query()->where('name', BackupRemoteStorageType::NAME_CUSTOM_S3)->firstOrFail();

    expect($ftpType->id)->not->toBe(2)
        ->and($awsS3Type->id)->not->toBe(3)
        ->and($customS3Type->id)->not->toBe(4);

    $ftpFields = Livewire::test(CreateBackupRemoteStorage::class)
        ->fillForm([
            'backup_remote_storage_type_id' => (string) $ftpType->id,
        ])
        ->instance()
        ->getSchema('form')
        ->getFlatFields();

    expect($ftpFields)->toHaveKey('host')
        ->and($ftpFields)->not->toHaveKey('access_key')
        ->and($ftpFields)->not->toHaveKey('endpoint');

    $awsS3Fields = Livewire::test(CreateBackupRemoteStorage::class)
        ->fillForm([
            'backup_remote_storage_type_id' => (string) $awsS3Type->id,
        ])
        ->instance()
        ->getSchema('form')
        ->getFlatFields();

    expect($awsS3Fields)->not->toHaveKey('host')
        ->and($awsS3Fields)->toHaveKey('access_key')
        ->and($awsS3Fields)->not->toHaveKey('endpoint');

    $customS3Fields = Livewire::test(CreateBackupRemoteStorage::class)
        ->fillForm([
            'backup_remote_storage_type_id' => (string) $customS3Type->id,
        ])
        ->instance()
        ->getSchema('form')
        ->getFlatFields();

    expect($customS3Fields)->not->toHaveKey('host')
        ->and($customS3Fields)->toHaveKey('access_key')
        ->and($customS3Fields)->toHaveKey('endpoint');
});

test('remote storage connection test uses the configured port', function () {
    $this->seed(BackupRemoteStorageTypeOptionsSeeder::class);
    $sftpType = BackupRemoteStorageType::query()->where('driver', BackupRemoteStorageType::DRIVER_SFTP)->firstOrFail();

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
        'backup_remote_storage_type_id' => (string) $sftpType->id,
        'host' => 'storage.example.com',
        'port' => '2222',
        'username' => 'deploy',
        'password' => 'secret',
        'directory' => '/',
    ]);

    expect($result['error'])->toBeFalse()
        ->and(config('filesystems.disks.temp_storage.port'))->toBe(2222);
});

test('remote storage connection test defaults blank ports to ftp port', function () {
    $this->seed(BackupRemoteStorageTypeOptionsSeeder::class);
    $sftpType = BackupRemoteStorageType::query()->where('driver', BackupRemoteStorageType::DRIVER_SFTP)->firstOrFail();

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
        'backup_remote_storage_type_id' => (string) $sftpType->id,
        'host' => 'storage.example.com',
        'port' => '',
        'username' => 'deploy',
        'password' => 'secret',
        'directory' => '/',
    ]);

    expect($result['error'])->toBeFalse()
        ->and(config('filesystems.disks.temp_storage.port'))->toBe(21);
});
