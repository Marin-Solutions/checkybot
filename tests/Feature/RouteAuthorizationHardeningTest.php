<?php

use App\Models\Backup;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use App\Models\SeoCheck;
use App\Models\Server;
use App\Models\ServerLogCategory;
use App\Models\ServerLogFileHistory;
use App\Models\User;
use App\Models\Website;
use App\Services\SeoReportGenerationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->createResourcePermissions('Server');
});

test('shell script download routes require valid signatures and allow owner-signed access', function () {
    $owner = User::factory()->create();
    $server = Server::factory()->create(['created_by' => $owner->id]);

    ServerLogCategory::factory()->create([
        'server_id' => $server->id,
        'should_collect' => true,
    ]);

    $storageType = BackupRemoteStorageType::query()->forceCreate([
        'name' => 'FTP',
        'driver' => 'ftp',
        'flag_active' => true,
    ]);
    $storage = BackupRemoteStorageConfig::query()->create([
        'backup_remote_storage_type_id' => $storageType->id,
        'label' => 'Backup storage',
        'created_by' => $owner->id,
        'host' => 'backup.example.com',
        'port' => 21,
        'username' => 'deployer',
        'password' => 'secret',
        'directory' => '/',
    ]);

    $backup = Backup::query()->create([
        'server_id' => $server->id,
        'created_by' => $owner->id,
        'dir_path' => '/var/www/html',
        'remote_storage_id' => $storage->id,
        'remote_storage_path' => '/',
        'interval_id' => 'daily',
        'max_amount_backups' => 1,
        'compression_type' => 'zip',
        'delete_local_on_fail' => false,
    ]);

    $this->get("/reporter/{$server->id}/{$owner->id}")
        ->assertForbidden();

    $this->get("/log-reporter/{$server->id}/{$owner->id}")
        ->assertForbidden();

    $this->get("/backup-folder/{$backup->id}/{$server->id}/{$owner->id}/0")
        ->assertForbidden();

    $reporterUrl = URL::temporarySignedRoute('server-info.script.download', now()->addMinutes(10), [
        'server_id' => $server->id,
        'user' => $owner->id,
    ]);

    $logReporterUrl = URL::temporarySignedRoute('server-log.script.download', now()->addMinutes(10), [
        'server_id' => $server->id,
        'user' => $owner->id,
    ]);

    $backupUrl = URL::temporarySignedRoute('backup.script.download', now()->addMinutes(10), [
        'backup_id' => $backup->id,
        'server_id' => $server->id,
        'user' => $owner->id,
        'init' => 0,
    ]);

    $this->get($reporterUrl)
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="reporter_server_info.sh"');

    $this->get($logReporterUrl)
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="log_reporter_server_info.sh"');

    $this->get($backupUrl)
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="backup_folder.sh"');
});

test('guests cannot access api v1 server endpoints', function () {
    $server = Server::factory()->create();

    $this->getJson('/api/v1/servers')->assertUnauthorized();
    $this->postJson('/api/v1/servers', [
        'name' => 'API Server',
        'ip' => '192.168.1.50',
        'description' => 'Created from test',
    ])->assertUnauthorized();
    $this->getJson("/api/v1/servers/{$server->id}")->assertUnauthorized();
});

test('server owners can view their own servers through the api without panel permissions', function () {
    $owner = User::factory()->create();
    $server = Server::factory()->create([
        'created_by' => $owner->id,
        'last_reporter_ip' => '198.51.100.24',
        'last_reporter_user_agent' => 'checkybot-reporter/1.0',
        'last_reporter_seen_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($owner)
        ->getJson("/api/v1/servers/{$server->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $server->id)
        ->assertJsonPath('data.last_reporter_ip', '198.51.100.24')
        ->assertJsonPath('data.last_reporter_user_agent', 'checkybot-reporter/1.0')
        ->assertJsonPath('data.last_reporter_seen_at', fn (?string $value): bool => filled($value));
});

test('users cannot view another users server through the api even with server permissions', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $server = Server::factory()->create(['created_by' => $owner->id]);

    $owner->givePermissionTo('View:Server');
    $attacker->givePermissionTo('View:Server');

    $this->actingAs($attacker)
        ->getJson("/api/v1/servers/{$server->id}")
        ->assertForbidden();
});

test('seo report downloads are isolated to the owning user', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $website = Website::factory()->create([
        'created_by' => $owner->id,
        'name' => 'Owned Website',
    ]);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
        'finished_at' => now(),
    ]);

    $filename = app(SeoReportGenerationService::class)->generateComprehensiveReport($seoCheck, 'json');

    $this->actingAs($otherUser)
        ->get(route('seo.report.download', ['filename' => $filename]))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('seo.report.download', ['filename' => $filename]))
        ->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
});

test('server log file downloads are limited to the owning server user', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $server = Server::factory()->create(['created_by' => $owner->id]);
    $category = ServerLogCategory::factory()->create(['server_id' => $server->id]);
    $file = ServerLogFileHistory::factory()->create([
        'server_log_category_id' => $category->id,
        'log_file_name' => 'ServerLogFiles/app.log',
    ]);

    Storage::put('ServerLogFiles/app.log', 'log contents');

    $this->actingAs($otherUser)
        ->get(route('server-log-file-history.download', $file))
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('server-log-file-history.download', $file))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=app.log');
});
