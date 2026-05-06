<?php

use App\Models\Backup;
use App\Models\BackupHistory;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogCategory;
use App\Models\ServerLogFileHistory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('server history intake trusts a valid token when reporter ip changes', function () {
    $server = Server::factory()->create([
        'ip' => '192.0.2.10',
        'token' => 'server-token',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->withHeaders([
            'Authorization' => 'Bearer server-token',
            'User-Agent' => 'checkybot-reporter/1.0',
        ])
        ->postJson('/api/v1/server-history', [
            's' => $server->id,
            'cpu_load' => '0.25',
            'cpu_cores' => 4,
            'ram_free_percentage' => '70',
            'ram_free' => '1024000',
            'disk_free_percentage' => '55',
            'disk_free_bytes' => '2048000',
        ])
        ->assertOk();

    assertDatabaseHas('server_information_history', [
        'server_id' => $server->id,
        'cpu_load' => '0.25',
    ]);

    $server->refresh();

    expect($server->last_reporter_ip)->toBe('198.51.100.24')
        ->and($server->last_reporter_user_agent)->toBe('checkybot-reporter/1.0')
        ->and($server->last_reporter_seen_at)->not->toBeNull();
});

test('server log intake trusts a valid token when reporter ip changes', function () {
    Storage::fake('local');

    $server = Server::factory()->create([
        'ip' => '192.0.2.10',
        'token' => 'server-token',
    ]);
    $category = ServerLogCategory::factory()->create(['server_id' => $server->id]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->withHeaders([
            'Authorization' => 'Bearer server-token',
            'User-Agent' => 'checkybot-log-reporter/1.0',
        ])
        ->post('/api/v1/server-log-history', [
            'li' => $category->id,
            'log' => UploadedFile::fake()->create('syslog.log', 1, 'text/plain'),
        ])
        ->assertOk();

    expect(ServerLogFileHistory::query()->where('server_log_category_id', $category->id)->exists())->toBeTrue();

    $server->refresh();

    expect($server->last_reporter_ip)->toBe('198.51.100.24')
        ->and($server->last_reporter_user_agent)->toBe('checkybot-log-reporter/1.0')
        ->and($server->last_reporter_seen_at)->not->toBeNull();
});

test('backup history intake trusts a valid token when reporter ip changes', function () {
    $server = Server::factory()->create([
        'ip' => '192.0.2.10',
        'token' => 'server-token',
    ]);
    $backup = Backup::query()->create([
        'server_id' => $server->id,
        'dir_path' => '/var/www/html',
        'remote_storage_id' => 1,
        'remote_storage_path' => '/',
        'interval_id' => '1',
        'compression_type' => 'zip',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->withHeaders([
            'Authorization' => 'Bearer server-token',
            'User-Agent' => 'checkybot-backup-reporter/1.0',
        ])
        ->postJson('/api/v1/backup-history', [
            'bi' => $backup->id,
            'nf' => 'site-backup.zip',
            'sf' => 2048,
            'iz' => 1,
            'iu' => 1,
        ])
        ->assertOk();

    expect(BackupHistory::query()->where('backup_id', $backup->id)->exists())->toBeTrue();

    $server->refresh();

    expect($server->last_reporter_ip)->toBe('198.51.100.24')
        ->and($server->last_reporter_user_agent)->toBe('checkybot-backup-reporter/1.0')
        ->and($server->last_reporter_seen_at)->not->toBeNull();
});

test('server reporter intake still rejects invalid tokens', function () {
    $server = Server::factory()->create([
        'ip' => '192.0.2.10',
        'token' => 'server-token',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson('/api/v1/server-history', [
            's' => $server->id,
            'cpu_load' => '0.25',
            'ram_free_percentage' => '70',
            'ram_free' => '1024000',
            'disk_free_percentage' => '55',
            'disk_free_bytes' => '2048000',
        ])
        ->assertUnauthorized();

    expect(ServerInformationHistory::query()->where('server_id', $server->id)->exists())->toBeFalse();

    $server->refresh();

    expect($server->last_reporter_ip)->toBeNull()
        ->and($server->last_reporter_seen_at)->toBeNull();
});
