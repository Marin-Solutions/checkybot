<?php

use App\Models\Backup;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use App\Models\Server;

function backupWithRemoteStorage(string $driver, array $storageOverrides = [], array $backupOverrides = []): Backup
{
    $type = BackupRemoteStorageType::query()->forceCreate([
        'name' => strtoupper($driver),
        'driver' => $driver,
        'flag_active' => true,
    ]);

    $storage = BackupRemoteStorageConfig::query()->create(array_merge([
        'label' => $driver.' backup storage',
        'backup_remote_storage_type_id' => $type->id,
        'host' => 'backup.example.com',
        'port' => $driver === 'sftp' ? 22 : 21,
        'username' => 'backup-user',
        'password' => 'backup-pass',
        'directory' => '/server-root',
        'access_key' => 'access-key',
        'secret_key' => 'secret-key',
        'bucket' => 'checkybot-backups',
        'region' => 'eu-central-1',
        'endpoint' => null,
    ], $storageOverrides));

    $server = Server::factory()->create([
        'token' => 'server-token',
    ]);

    return Backup::query()->create(array_merge([
        'server_id' => $server->id,
        'dir_path' => '/var/www/app',
        'remote_storage_id' => $storage->id,
        'remote_storage_path' => '/daily',
        'interval_id' => 'daily',
        'max_amount_backups' => 1,
        'compression_type' => 'tar',
        'backup_filename' => 'app-backup',
        'delete_local_on_fail' => false,
    ], $backupOverrides));
}

test('backup script uploads ftp backups over ftp', function () {
    $script = backupWithRemoteStorage('ftp')->backupScript();

    expect($script)
        ->toContain("curl -sS --fail -T \${FILE_NAME} --user 'backup-user:backup-pass' 'ftp://backup.example.com:21/server-root/daily/'")
        ->not->toContain('aws s3 cp')
        ->not->toContain('sftp://backup.example.com');
});

test('backup script uploads sftp backups over sftp', function () {
    $script = backupWithRemoteStorage('sftp')->backupScript();

    expect($script)
        ->toContain("curl -sS --fail -T \${FILE_NAME} --user 'backup-user:backup-pass' 'sftp://backup.example.com:22/server-root/daily/'")
        ->not->toContain("'ftp://backup.example.com")
        ->not->toContain('aws s3 cp');
});

test('backup script uploads s3 backups with aws cli destination config', function () {
    $script = backupWithRemoteStorage('s3', [
        'endpoint' => 'https://storage.example.com',
    ])->backupScript();

    expect($script)
        ->toContain("AWS_ACCESS_KEY_ID='access-key' AWS_SECRET_ACCESS_KEY='secret-key' AWS_DEFAULT_REGION='eu-central-1' aws s3 cp \"\${FILE_NAME}\" 's3://checkybot-backups/daily/'\"\${FILE_NAME}\" --only-show-errors --endpoint-url 'https://storage.example.com'")
        ->not->toContain('ftp://')
        ->not->toContain('sftp://');
});
