<?php

use App\Filament\Resources\BackupsResource;
use App\Filament\Resources\BackupsResource\Pages\CreateBackups;
use App\Filament\Resources\BackupsResource\Pages\EditBackups;
use App\Filament\Resources\BackupsResource\Pages\ListBackups;
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

test('backup edit saves matching zip password confirmation', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $backup = createBackupResourceBackupForUser($user->id, [
        'password' => 'current-password',
    ]);

    Livewire::test(EditBackups::class, ['record' => $backup->id])
        ->fillForm([
            'password' => 'new-password',
            'confirm_password' => 'new-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($backup->refresh()->password)->toBe('new-password');
});

test('backup edit allows unrelated changes when zip password is unchanged', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $backup = createBackupResourceBackupForUser($user->id, [
        'password' => 'current-password',
    ]);

    Livewire::test(EditBackups::class, ['record' => $backup->id])
        ->fillForm([
            'remote_storage_path' => '/archives',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $backup->refresh();

    expect($backup->remote_storage_path)->toBe('/archives')
        ->and($backup->password)->toBe('current-password');
});

test('backup edit preserves stored zip password when password fields are cleared', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $backup = createBackupResourceBackupForUser($user->id, [
        'password' => 'current-password',
    ]);

    Livewire::test(EditBackups::class, ['record' => $backup->id])
        ->fillForm([
            'password' => '',
            'confirm_password' => '',
            'remote_storage_path' => '/archives',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $backup->refresh();

    expect($backup->remote_storage_path)->toBe('/archives')
        ->and($backup->password)->toBe('current-password');
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

test('backup list shows expected run freshness evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup']);
    $backup = createBackupResourceBackupForUser($user->id);

    createBackupResourceHistory($backup, [
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $backup->forceFill(['last_history_at' => now()->subDays(2)])->save();

    Livewire::test(ListBackups::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$backup])
        ->assertSee('Missed run')
        ->assertSee('Expected By')
        ->assertSee('Latest Run');
});

test('backup list filters by freshness state', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup']);

    $missedBackup = createBackupResourceBackupForUser($user->id);
    createBackupResourceHistory($missedBackup, [
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $missedBackup->forceFill(['last_history_at' => now()->subDays(2)])->save();

    $awaitingBackup = createBackupResourceBackupForUser($user->id, [
        'dir_path' => '/var/www/awaiting',
        'first_run_at' => now()->addHour(),
    ]);

    $freshBackup = createBackupResourceBackupForUser($user->id, [
        'dir_path' => '/var/www/fresh',
    ]);
    createBackupResourceHistory($freshBackup, [
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);
    $freshBackup->forceFill(['last_history_at' => now()->subHour()])->save();

    Livewire::test(ListBackups::class)
        ->filterTable('freshness_state', 'missed_run')
        ->assertCanSeeTableRecords([$missedBackup])
        ->assertCanNotSeeTableRecords([$awaitingBackup, $freshBackup]);

    Livewire::test(ListBackups::class)
        ->filterTable('freshness_state', 'awaiting_first_run')
        ->assertCanSeeTableRecords([$awaitingBackup])
        ->assertCanNotSeeTableRecords([$missedBackup, $freshBackup]);

    Livewire::test(ListBackups::class)
        ->filterTable('freshness_state', 'fresh')
        ->assertCanSeeTableRecords([$freshBackup])
        ->assertCanNotSeeTableRecords([$missedBackup, $awaitingBackup]);
});

test('backup list filters by latest run zip and upload failures', function () {
    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['View:Backup']);

    $zipFailedBackup = createBackupResourceBackupForUser($user->id, [
        'dir_path' => '/var/www/zip-failed',
    ]);
    createBackupResourceHistory($zipFailedBackup, [
        'is_zipped' => false,
        'is_uploaded' => false,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $uploadFailedBackup = createBackupResourceBackupForUser($user->id, [
        'dir_path' => '/var/www/upload-failed',
    ]);
    createBackupResourceHistory($uploadFailedBackup, [
        'is_zipped' => false,
        'is_uploaded' => false,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    createBackupResourceHistory($uploadFailedBackup, [
        'is_zipped' => true,
        'is_uploaded' => false,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $recoveredBackup = createBackupResourceBackupForUser($user->id, [
        'dir_path' => '/var/www/recovered',
    ]);
    createBackupResourceHistory($recoveredBackup, [
        'is_zipped' => false,
        'is_uploaded' => false,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    createBackupResourceHistory($recoveredBackup, [
        'is_zipped' => true,
        'is_uploaded' => true,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    Livewire::test(ListBackups::class)
        ->filterTable('latest_run_failure', 'zip_failed')
        ->assertCanSeeTableRecords([$zipFailedBackup])
        ->assertCanNotSeeTableRecords([$uploadFailedBackup, $recoveredBackup]);

    Livewire::test(ListBackups::class)
        ->filterTable('latest_run_failure', 'upload_failed')
        ->assertCanSeeTableRecords([$zipFailedBackup, $uploadFailedBackup])
        ->assertCanNotSeeTableRecords([$recoveredBackup]);
});

test('backup list is scoped to the authenticated owner', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = \App\Models\User::factory()->create();
    $user->givePermissionTo(['View:Backup']);
    $visibleBackup = createBackupResourceBackupForUser($user->id);
    $hiddenBackup = createBackupResourceBackupForUser($otherUser->id);

    Livewire::test(ListBackups::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleBackup])
        ->assertCanNotSeeTableRecords([$hiddenBackup]);
});

test('backup form relationship selects only expose owned servers and storage configs', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = \App\Models\User::factory()->create();
    $user->givePermissionTo(['Create:Backup']);
    $visibleBackup = createBackupResourceBackupForUser($user->id);
    $hiddenBackup = createBackupResourceBackupForUser($otherUser->id);

    Livewire::test(CreateBackups::class)
        ->assertFormFieldExists('server_id', function ($field) use ($visibleBackup, $hiddenBackup): bool {
            $options = $field->getOptions();

            return array_key_exists($visibleBackup->server_id, $options)
                && ! array_key_exists($hiddenBackup->server_id, $options);
        })
        ->assertFormFieldExists('remote_storage_id', function ($field) use ($visibleBackup, $hiddenBackup): bool {
            $options = $field->getOptions();

            return array_key_exists($visibleBackup->remote_storage_id, $options)
                && ! array_key_exists($hiddenBackup->remote_storage_id, $options);
        });
});

test('backup policies reject records owned by another user', function () {
    $user = \App\Models\User::factory()->create();
    $otherUser = \App\Models\User::factory()->create();
    $this->actingAs($user);
    $user->givePermissionTo(['View:Backup', 'Update:Backup', 'Delete:Backup']);
    $visibleBackup = createBackupResourceBackupForUser($user->id);
    $hiddenBackup = createBackupResourceBackupForUser($otherUser->id);

    expect($user->can('view', $visibleBackup))->toBeTrue()
        ->and($user->can('update', $visibleBackup))->toBeTrue()
        ->and($user->can('delete', $visibleBackup))->toBeTrue()
        ->and($user->can('view', $hiddenBackup))->toBeFalse()
        ->and($user->can('update', $hiddenBackup))->toBeFalse()
        ->and($user->can('delete', $hiddenBackup))->toBeFalse();
});

test('backup edit page rejects records owned by another user', function () {
    $user = $this->actingAsAdmin();
    $otherUser = \App\Models\User::factory()->create();
    $user->givePermissionTo(['View:Backup', 'Update:Backup']);
    $hiddenBackup = createBackupResourceBackupForUser($otherUser->id);

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::test(EditBackups::class, ['record' => $hiddenBackup->id]);
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
        'created_by' => $userId,
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
        'created_by' => $userId,
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
