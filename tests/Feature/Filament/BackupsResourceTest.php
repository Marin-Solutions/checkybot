<?php

use App\Filament\Resources\BackupsResource;
use App\Filament\Resources\BackupsResource\Pages\EditBackups;
use App\Filament\Resources\BackupsResource\RelationManagers\HistoriesRelationManager;
use App\Models\Backup;
use App\Models\BackupHistory;
use App\Models\BackupIntervalOption;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use App\Models\Server;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->createResourcePermissions('Backup');
});

test('backup edit page exposes recent backup history', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $backup = createBackupResourceBackupForUser($user->id);

    Livewire::test(EditBackups::class, ['record' => $backup->id])
        ->assertSuccessful()
        ->assertSee('Backup History');

    expect(BackupsResource::getRelations())
        ->toContain(HistoriesRelationManager::class);
});

test('backup edit rejects mismatched zip password confirmation', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $backup = createBackupResourceBackupForUser($user->id, [
        'password' => 'current-password',
    ]);

    Livewire::test(EditBackups::class, ['record' => $backup->id])
        ->fillForm([
            'password' => 'new-password',
            'confirm_password' => 'different-password',
        ])
        ->call('save')
        ->assertNotified('Passwords do not match');

    expect($backup->refresh()->password)->toBe('current-password');
});

test('backup history relation manager shows run evidence for the selected backup', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $backup = createBackupResourceBackupForUser($user->id);
    $otherBackup = createBackupResourceBackupForUser($user->id);

    $visibleHistory = createBackupResourceHistory($backup, [
        'filename' => 'app-backup_20260506_080000.zip',
        'filesize' => 1536000,
        'is_zipped' => true,
        'is_uploaded' => false,
        'message' => 'FTP upload timed out after creating the archive.',
        'created_at' => Carbon::parse('2026-05-06 08:00:00'),
        'updated_at' => Carbon::parse('2026-05-06 08:00:00'),
    ]);
    $hiddenHistory = createBackupResourceHistory($otherBackup, [
        'filename' => 'other-backup_20260506_080000.zip',
    ]);

    Livewire::test(HistoriesRelationManager::class, [
        'ownerRecord' => $backup,
        'pageClass' => EditBackups::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleHistory])
        ->assertCanNotSeeTableRecords([$hiddenHistory])
        ->assertSee('Created')
        ->assertSee('Failed')
        ->assertSee('app-backup_20260506_080000.zip')
        ->assertSee('FTP upload timed out after creating the archive.');
});

function createBackupResourceBackupForUser(int $userId, array $attributes = []): Backup
{
    $server = Server::factory()->create(['created_by' => $userId]);
    $storageType = BackupRemoteStorageType::query()->forceCreate([
        'name' => 'FTP',
        'driver' => 'ftp',
        'flag_active' => true,
    ]);
    $remoteStorage = BackupRemoteStorageConfig::query()->create([
        'backup_remote_storage_type_id' => $storageType->id,
        'label' => 'Primary FTP',
        'host' => 'backup.example.com',
        'username' => 'deploy',
        'password' => 'secret',
        'directory' => '/',
    ]);
    $interval = BackupIntervalOption::query()->create([
        'value' => 1,
        'unit' => 'day',
        'expression' => '0 2 * * *',
    ]);

    return Backup::query()->create(array_merge([
        'server_id' => $server->id,
        'dir_path' => '/var/www/html',
        'remote_storage_id' => $remoteStorage->id,
        'remote_storage_path' => '/',
        'interval_id' => $interval->id,
        'first_run_at' => Carbon::parse('2026-05-06 02:00:00'),
        'max_amount_backups' => 5,
        'compression_type' => 'zip',
        'delete_local_on_fail' => false,
    ], $attributes));
}

function createBackupResourceHistory(Backup $backup, array $attributes = []): BackupHistory
{
    return BackupHistory::query()->create(array_merge([
        'backup_id' => $backup->id,
        'filename' => 'backup_20260506_080000.zip',
        'filesize' => 1024,
        'is_zipped' => true,
        'is_uploaded' => true,
        'message' => null,
    ], $attributes));
}
