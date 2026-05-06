<?php

use App\Filament\Resources\BackupRemoteStorageResource;
use App\Filament\Resources\BackupRemoteStorageResource\Pages\ListBackupRemoteStorages;
use App\Filament\Resources\BackupsResource;
use App\Filament\Resources\BackupsResource\Pages\CreateBackups;
use App\Filament\Resources\BackupsResource\Pages\EditBackups;
use App\Filament\Resources\BackupsResource\Pages\ListBackups;
use App\Models\Backup;
use App\Models\BackupIntervalOption;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use App\Models\Server;
use App\Models\User;
use App\Policies\BackupPolicy;
use App\Policies\BackupRemoteStorageConfigPolicy;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

function createBackupManagerStorageType(): BackupRemoteStorageType
{
    return BackupRemoteStorageType::query()->forceCreate([
        'name' => 'FTP',
        'driver' => 'ftp',
        'flag_active' => true,
    ]);
}

function createBackupManagerInterval(): BackupIntervalOption
{
    return BackupIntervalOption::query()->create([
        'value' => 1,
        'unit' => 'day',
        'expression' => '0 0 * * *',
    ]);
}

function createBackupManagerStorage(User $user, array $attributes = []): BackupRemoteStorageConfig
{
    return BackupRemoteStorageConfig::query()->create(array_merge([
        'backup_remote_storage_type_id' => createBackupManagerStorageType()->id,
        'label' => 'Backup storage',
        'created_by' => $user->id,
        'host' => 'backup.example.com',
        'port' => 21,
        'username' => 'deployer',
        'password' => 'secret',
        'directory' => '/',
    ], $attributes));
}

function createBackupManagerBackup(User $user, array $attributes = []): Backup
{
    $server = $attributes['server'] ?? Server::factory()->create(['created_by' => $user->id]);
    $storage = $attributes['storage'] ?? createBackupManagerStorage($user);
    $interval = $attributes['interval'] ?? createBackupManagerInterval();

    return Backup::query()->create([
        'server_id' => $server->id,
        'dir_path' => $attributes['dir_path'] ?? '/var/www/html',
        'remote_storage_id' => $storage->id,
        'remote_storage_path' => '/',
        'interval_id' => $interval->id,
        'max_amount_backups' => 3,
        'compression_type' => 'zip',
        'delete_local_on_fail' => false,
    ]);
}

beforeEach(function () {
    $this->createResourcePermissions('Backup');
    $this->createResourcePermissions('BackupRemoteStorageConfig');
});

test('backup list only shows backups owned through both server and remote storage', function () {
    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:Backup', 'View:Backup']);
    $otherUser = User::factory()->create();

    $ownBackup = createBackupManagerBackup($user, ['dir_path' => '/srv/owned-app']);
    $otherBackup = createBackupManagerBackup($otherUser, ['dir_path' => '/srv/other-app']);

    Livewire::test(ListBackups::class)
        ->assertCanSeeTableRecords([$ownBackup])
        ->assertCanNotSeeTableRecords([$otherBackup])
        ->assertSee('/srv/owned-app')
        ->assertDontSee('/srv/other-app');
});

test('remote storage list only shows storage configs created by the current user', function () {
    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:BackupRemoteStorageConfig', 'View:BackupRemoteStorageConfig']);
    $otherUser = User::factory()->create();

    $ownStorage = createBackupManagerStorage($user, ['host' => 'owned-storage.example.com']);
    $otherStorage = createBackupManagerStorage($otherUser, ['host' => 'other-storage.example.com']);

    Livewire::test(ListBackupRemoteStorages::class)
        ->assertCanSeeTableRecords([$ownStorage])
        ->assertCanNotSeeTableRecords([$otherStorage])
        ->assertSee('owned-storage.example.com')
        ->assertDontSee('other-storage.example.com');
});

test('backup form only offers current users servers and remote storage configs', function () {
    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:Backup', 'Create:Backup']);
    $otherUser = User::factory()->create();

    $ownServer = Server::factory()->create(['created_by' => $user->id, 'name' => 'Owned backup server']);
    $otherServer = Server::factory()->create(['created_by' => $otherUser->id, 'name' => 'Other backup server']);
    $ownStorage = createBackupManagerStorage($user, ['label' => 'Owned backup storage']);
    $otherStorage = createBackupManagerStorage($otherUser, ['label' => 'Other backup storage']);

    Livewire::test(CreateBackups::class)
        ->assertFormFieldExists('server_id', function (Select $field) use ($ownServer, $otherServer): bool {
            return array_key_exists($ownServer->id, $field->getSearchResults('backup server'))
                && ! array_key_exists($otherServer->id, $field->getSearchResults('backup server'));
        })
        ->assertFormFieldExists('remote_storage_id', function (Select $field) use ($ownStorage, $otherStorage): bool {
            return array_key_exists($ownStorage->id, $field->getSearchResults('backup storage'))
                && ! array_key_exists($otherStorage->id, $field->getSearchResults('backup storage'));
        });
});

test('backup policy denies another users backup and mixed ownership backup even with permissions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $user->givePermissionTo([
        'View:Backup',
        'Update:Backup',
        'Delete:Backup',
        'Restore:Backup',
        'ForceDelete:Backup',
        'Replicate:Backup',
    ]);

    $ownBackup = createBackupManagerBackup($user);
    $otherBackup = createBackupManagerBackup($otherUser);
    $mixedBackup = createBackupManagerBackup($user, [
        'storage' => createBackupManagerStorage($otherUser),
    ]);
    $policy = new BackupPolicy;

    expect($policy->view($user, $ownBackup))->toBeTrue()
        ->and($policy->update($user, $ownBackup))->toBeTrue()
        ->and($policy->delete($user, $ownBackup))->toBeTrue()
        ->and($policy->view($user, $otherBackup))->toBeFalse()
        ->and($policy->update($user, $otherBackup))->toBeFalse()
        ->and($policy->delete($user, $otherBackup))->toBeFalse()
        ->and($policy->restore($user, $otherBackup))->toBeFalse()
        ->and($policy->forceDelete($user, $otherBackup))->toBeFalse()
        ->and($policy->replicate($user, $otherBackup))->toBeFalse()
        ->and($policy->view($user, $mixedBackup))->toBeFalse()
        ->and($policy->update($user, $mixedBackup))->toBeFalse();
});

test('remote storage policy denies another users storage config even with permissions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $user->givePermissionTo([
        'View:BackupRemoteStorageConfig',
        'Update:BackupRemoteStorageConfig',
        'Delete:BackupRemoteStorageConfig',
        'Restore:BackupRemoteStorageConfig',
        'ForceDelete:BackupRemoteStorageConfig',
        'Replicate:BackupRemoteStorageConfig',
    ]);

    $ownStorage = createBackupManagerStorage($user);
    $otherStorage = createBackupManagerStorage($otherUser);
    $policy = new BackupRemoteStorageConfigPolicy;

    expect($policy->view($user, $ownStorage))->toBeTrue()
        ->and($policy->update($user, $ownStorage))->toBeTrue()
        ->and($policy->delete($user, $ownStorage))->toBeTrue()
        ->and($policy->view($user, $otherStorage))->toBeFalse()
        ->and($policy->update($user, $otherStorage))->toBeFalse()
        ->and($policy->delete($user, $otherStorage))->toBeFalse()
        ->and($policy->restore($user, $otherStorage))->toBeFalse()
        ->and($policy->forceDelete($user, $otherStorage))->toBeFalse()
        ->and($policy->replicate($user, $otherStorage))->toBeFalse();
});

test('direct edit routes cannot open another users backup manager records', function () {
    $user = $this->actingAsAdmin();
    $user->givePermissionTo([
        'ViewAny:Backup',
        'View:Backup',
        'Update:Backup',
        'ViewAny:BackupRemoteStorageConfig',
        'View:BackupRemoteStorageConfig',
        'Update:BackupRemoteStorageConfig',
    ]);
    $otherUser = User::factory()->create();

    $otherBackup = createBackupManagerBackup($otherUser);
    $otherStorage = $otherBackup->remoteStorage;

    $this->get(BackupsResource::getUrl('edit', ['record' => $otherBackup]))
        ->assertStatus(404);

    $this->get(BackupRemoteStorageResource::getUrl('edit', ['record' => $otherStorage]))
        ->assertStatus(404);
});

test('backup create and save reject crafted ownership bypass values', function () {
    $user = $this->actingAsAdmin();
    $user->givePermissionTo([
        'ViewAny:Backup',
        'View:Backup',
        'Create:Backup',
        'Update:Backup',
    ]);
    $otherUser = User::factory()->create();

    $ownBackup = createBackupManagerBackup($user);
    $otherServer = Server::factory()->create(['created_by' => $otherUser->id]);
    $otherStorage = createBackupManagerStorage($otherUser);
    $interval = createBackupManagerInterval();

    Livewire::test(CreateBackups::class)
        ->fillForm([
            'server_id' => $otherServer->id,
            'remote_storage_id' => $otherStorage->id,
            'dir_path' => '/srv/bypass',
            'remote_storage_path' => '/',
            'interval_id' => $interval->id,
            'max_amount_backups' => 1,
            'compression_type' => 'zip',
            'password' => 'secret',
            'confirm_password' => 'secret',
            'delete_local_on_fail' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['server_id', 'remote_storage_id']);

    Livewire::test(EditBackups::class, ['record' => $ownBackup->id])
        ->fillForm([
            'server_id' => $otherServer->id,
            'remote_storage_id' => $otherStorage->id,
        ])
        ->call('save')
        ->assertHasFormErrors(['server_id', 'remote_storage_id']);

    $ownBackup->refresh();

    expect($ownBackup->server_id)->not->toBe($otherServer->id)
        ->and($ownBackup->remote_storage_id)->not->toBe($otherStorage->id);
});

test('copy backup script command is blank for backups outside the current users ownership boundary', function () {
    $user = $this->actingAsAdmin();
    $otherUser = User::factory()->create();

    $ownBackup = createBackupManagerBackup($user);
    $otherBackup = createBackupManagerBackup($otherUser);
    $mixedBackup = createBackupManagerBackup($user, [
        'storage' => createBackupManagerStorage($otherUser),
    ]);

    expect(Backup::copyCommand($ownBackup))->toContain('backup-folder')
        ->and(Backup::copyCommand($otherBackup))->toBe('')
        ->and(Backup::copyCommand($mixedBackup))->toBe('');
});
