<?php

use App\Models\Backup;
use App\Models\BackupIntervalOption;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use App\Models\Server;
use Carbon\Carbon;

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

test('backup script reports backup id in history payload', function () {
    Server::factory()->create();

    $backup = backupWithRemoteStorage('ftp');
    $script = $backup->backupScript();

    expect($backup->server_id)->not->toBe($backup->id)
        ->and($script)->toContain("\\\"bi\\\": {$backup->id}")
        ->and($script)->not->toContain("\\\"bi\\\": {$backup->server_id}");
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

test('backup script reports failure evidence with valid fallback values', function () {
    $backup = backupWithRemoteStorage('ftp');
    $script = $backup->backupScript();

    expect($script)
        ->toContain("FILE_SIZE=0\nIS_UPLOADED=0\nMESSAGE=\"\"")
        ->toContain('DO_ZIP=')
        ->toContain('MESSAGE="Compression failed: $DO_ZIP"')
        ->toContain('MESSAGE="Upload failed: $DO_UPLOAD_FILE"')
        ->toContain('MESSAGE_JSON=$(printf')
        ->toContain('s/\\r/\\\\r/g;s/\\t/\\\\t/g;s/\\n/\\\\n/g')
        ->toContain('\\"bi\\": '.$backup->id)
        ->toContain('\\"sf\\": $FILE_SIZE')
        ->toContain('\\"iu\\": $IS_UPLOADED')
        ->toContain('\\"msg\\": \\"$MESSAGE_JSON\\"');
});

test('backup script prunes old local backups after successful upload when retention is configured', function () {
    $script = backupWithRemoteStorage('ftp', [], [
        'max_amount_backups' => 3,
    ])->backupScript();

    expect($script)
        ->toContain('BACKUP_FILE_PREFIX=\'app-backup\'')
        ->toContain('BACKUP_FILE_EXTENSION=\'tar\'')
        ->toContain('MAX_AMOUNT_BACKUPS=3')
        ->toContain('cleanup_old_local_backups()')
        ->toContain('if [ "$MAX_AMOUNT_BACKUPS" -le 0 ]; then')
        ->toContain('BACKUP_STORAGE_DIR=$(dirname -- "$FILE_NAME")')
        ->toContain('BACKUP_STORAGE_BASENAME=$(basename -- "$BACKUP_FILE_PREFIX")')
        ->toContain('find "$BACKUP_STORAGE_DIR" -maxdepth 1 -type f -name "*.$BACKUP_FILE_EXTENSION" -print0')
        ->toContain('DELETE_COUNT=$((BACKUP_COUNT - MAX_AMOUNT_BACKUPS))')
        ->toContain('rm -f -- "$OLD_BACKUP_FILE"')
        ->toContain("IS_UPLOADED=1\n        cleanup_old_local_backups");
});

test('backup script removes local archive after failed upload when configured', function () {
    $script = backupWithRemoteStorage('ftp', [], [
        'delete_local_on_fail' => true,
    ])->backupScript();

    expect($script)
        ->toContain('DELETE_LOCAL_ON_FAIL=1')
        ->toContain("MESSAGE=\"Upload failed: \$DO_UPLOAD_FILE\"\n        if [ \"\$DELETE_LOCAL_ON_FAIL\" -eq 1 ]; then\n            rm -f -- \"\$FILE_NAME\"\n        fi");
});

test('backup script uses the generated file path when pruning path based default filenames', function () {
    $script = backupWithRemoteStorage('ftp', [], [
        'backup_filename' => null,
        'dir_path' => '/var/www/app',
        'max_amount_backups' => 2,
    ])->backupScript();

    expect($script)
        ->toContain("BACKUP_FILE_PREFIX='/var/www/app'")
        ->toContain('FILE_NAME="${BACKUP_FILE_PREFIX}_${TIMESTAMP}.${BACKUP_FILE_EXTENSION}"')
        ->toContain('BACKUP_STORAGE_DIR=$(dirname -- "$FILE_NAME")')
        ->toContain('BACKUP_STORAGE_BASENAME=$(basename -- "$BACKUP_FILE_PREFIX")');
});

test('generated backup script has valid bash syntax', function () {
    $script = backupWithRemoteStorage('ftp', [], [
        'max_amount_backups' => 2,
        'delete_local_on_fail' => true,
    ])->backupScript();

    $path = tempnam(sys_get_temp_dir(), 'checkybot-backup-script-');
    file_put_contents($path, $script);

    exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $status);
    unlink($path);

    expect(implode("\n", $output))->toBe('')
        ->and($status)->toBe(0);
});

test('monthly backup freshness uses calendar months instead of fixed thirty day windows', function () {
    Carbon::setTestNow('2026-02-28 12:00:00');

    $interval = BackupIntervalOption::query()->create([
        'value' => 1,
        'unit' => 'monthly',
        'expression' => '0 0 1 * *',
    ]);

    $backup = backupWithRemoteStorage('ftp', backupOverrides: [
        'interval_id' => (string) $interval->id,
    ]);
    $backup->forceFill(['last_history_at' => Carbon::parse('2026-01-31 12:00:00')])->save();

    expect($backup->freshnessThresholdAt()?->toDateTimeString())->toBe('2026-02-28 12:00:00')
        ->and($backup->isMissingExpectedRun())->toBeFalse();

    Carbon::setTestNow('2026-02-28 12:00:01');

    expect($backup->isMissingExpectedRun())->toBeTrue();

    Carbon::setTestNow();
});
