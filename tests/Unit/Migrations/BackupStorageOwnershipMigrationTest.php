<?php

use App\Models\BackupRemoteStorageType;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('storage ownership migration splits shared legacy configs per server owner', function () {
    $migration = require database_path('migrations/2026_05_06_214026_add_created_by_to_backup_remote_storage_config_table.php');
    $migration->down();

    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstServer = Server::factory()->create(['created_by' => $firstOwner->id]);
    $secondServer = Server::factory()->create(['created_by' => $secondOwner->id]);
    $storageType = BackupRemoteStorageType::query()->forceCreate([
        'name' => 'FTP',
        'driver' => 'ftp',
        'flag_active' => true,
    ]);

    $storageId = DB::table('backup_remote_storage_config')->insertGetId([
        'backup_remote_storage_type_id' => $storageType->id,
        'label' => 'Shared legacy storage',
        'host' => 'legacy-storage.example.com',
        'port' => 21,
        'username' => 'deployer',
        'password' => 'secret',
        'directory' => '/',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $firstBackupId = DB::table('backups')->insertGetId([
        'server_id' => $firstServer->id,
        'created_by' => null,
        'dir_path' => '/srv/first',
        'remote_storage_id' => $storageId,
        'remote_storage_path' => '/',
        'interval_id' => 'daily',
        'max_amount_backups' => 1,
        'compression_type' => 'zip',
        'delete_local_on_fail' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $secondBackupId = DB::table('backups')->insertGetId([
        'server_id' => $secondServer->id,
        'created_by' => null,
        'dir_path' => '/srv/second',
        'remote_storage_id' => $storageId,
        'remote_storage_path' => '/',
        'interval_id' => 'daily',
        'max_amount_backups' => 1,
        'compression_type' => 'zip',
        'delete_local_on_fail' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $storageIdsByOwner = DB::table('backup_remote_storage_config')
        ->where('host', 'legacy-storage.example.com')
        ->pluck('id', 'created_by');

    expect($storageIdsByOwner)->toHaveCount(2)
        ->and($storageIdsByOwner)->toHaveKeys([(string) $firstOwner->id, (string) $secondOwner->id])
        ->and(DB::table('backups')->where('id', $firstBackupId)->value('remote_storage_id'))->toBe($storageIdsByOwner[$firstOwner->id])
        ->and(DB::table('backups')->where('id', $secondBackupId)->value('remote_storage_id'))->toBe($storageIdsByOwner[$secondOwner->id]);
});

test('storage ownership migration ignores stale server owner ids', function () {
    $migration = require database_path('migrations/2026_05_06_214026_add_created_by_to_backup_remote_storage_config_table.php');
    $migration->down();

    $deletedOwner = User::factory()->create();
    $server = Server::factory()->create(['created_by' => $deletedOwner->id]);
    $deletedOwner->delete();

    $storageType = BackupRemoteStorageType::query()->forceCreate([
        'name' => 'FTP',
        'driver' => 'ftp',
        'flag_active' => true,
    ]);

    $storageId = DB::table('backup_remote_storage_config')->insertGetId([
        'backup_remote_storage_type_id' => $storageType->id,
        'label' => 'Orphaned legacy storage',
        'host' => 'orphaned-storage.example.com',
        'port' => 21,
        'username' => 'deployer',
        'password' => 'secret',
        'directory' => '/',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('backups')->insert([
        'server_id' => $server->id,
        'created_by' => null,
        'dir_path' => '/srv/orphaned',
        'remote_storage_id' => $storageId,
        'remote_storage_path' => '/',
        'interval_id' => 'daily',
        'max_amount_backups' => 1,
        'compression_type' => 'zip',
        'delete_local_on_fail' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('backup_remote_storage_config')->where('host', 'orphaned-storage.example.com')->count())->toBe(1)
        ->and(DB::table('backup_remote_storage_config')->where('id', $storageId)->value('created_by'))->toBeNull();
});
