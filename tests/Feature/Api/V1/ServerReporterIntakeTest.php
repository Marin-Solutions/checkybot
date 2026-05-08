<?php

use App\Enums\WebsiteServicesEnum;
use App\Mail\HealthStatusAlert;
use App\Models\Backup;
use App\Models\BackupHistory;
use App\Models\NotificationSetting;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogCategory;
use App\Models\ServerLogFileHistory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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
    expect($category->refresh()->last_collected_at)->not->toBeNull();

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
            'msg' => 'Upload completed',
        ])
        ->assertOk();

    $history = BackupHistory::query()->where('backup_id', $backup->id)->first();

    expect($history)->not->toBeNull()
        ->and($history?->message)->toBe('Upload completed');

    $server->refresh();

    expect($server->last_reporter_ip)->toBe('198.51.100.24')
        ->and($server->last_reporter_user_agent)->toBe('checkybot-backup-reporter/1.0')
        ->and($server->last_reporter_seen_at)->not->toBeNull();
});

test('backup history intake sends notification when archive creation fails', function () {
    Mail::fake();

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

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $server->created_by,
            'inspection' => WebsiteServicesEnum::BACKUP_MONITOR,
            'address' => 'ops@example.com',
        ]);

    $this->withHeaders([
        'Authorization' => 'Bearer server-token',
        'User-Agent' => 'checkybot-backup-reporter/1.0',
    ])
        ->postJson('/api/v1/backup-history', [
            'bi' => $backup->id,
            'nf' => 'site-backup.zip',
            'sf' => 0,
            'iz' => 0,
            'iu' => 0,
        ])
        ->assertOk();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'backup_failed'
            && $mail->status === 'danger'
            && str_contains($mail->summary, 'Backup archive creation failed')
            && str_contains($mail->summary, 'site-backup.zip');
    });
});

test('backup history intake sends notification when upload fails', function () {
    Mail::fake();

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

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $server->created_by,
            'inspection' => WebsiteServicesEnum::ALL_CHECK,
            'address' => 'ops@example.com',
        ]);

    $this->withHeaders([
        'Authorization' => 'Bearer server-token',
        'User-Agent' => 'checkybot-backup-reporter/1.0',
    ])
        ->postJson('/api/v1/backup-history', [
            'bi' => $backup->id,
            'nf' => 'site-backup.zip',
            'sf' => 2048,
            'iz' => 1,
            'iu' => 0,
        ])
        ->assertOk();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'backup_failed'
            && $mail->status === 'danger'
            && str_contains($mail->summary, 'upload failed')
            && str_contains($mail->summary, 'site-backup.zip');
    });
});

test('backup history intake does not send notification when backup succeeds', function () {
    Mail::fake();

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

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $server->created_by,
            'inspection' => WebsiteServicesEnum::BACKUP_MONITOR,
        ]);

    $this->withHeaders([
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

    Mail::assertNothingSent();
});

test('backup history intake clears missed run state and sends recovery notification', function () {
    Mail::fake();

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
        'stale_at' => now()->subHour(),
        'compression_type' => 'zip',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $server->created_by,
            'inspection' => WebsiteServicesEnum::BACKUP_MONITOR,
        ]);

    $this->withHeaders([
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

    $backup->refresh();

    expect($backup->stale_at)->toBeNull()
        ->and($backup->last_history_at)->not->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->status === 'healthy'
            && str_contains($mail->summary, 'Backup reporter recovered');
    });
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

test('server history intake rejects malformed metric payloads', function (array $override, string $field) {
    $server = Server::factory()->create([
        'ip' => '192.0.2.10',
        'token' => 'server-token',
    ]);

    $payload = array_merge([
        's' => $server->id,
        'cpu_load' => '0.25',
        'cpu_cores' => 4,
        'ram_free_percentage' => '70',
        'ram_free' => '1024000',
        'disk_free_percentage' => '55',
        'disk_free_bytes' => '2048000',
    ], $override);

    $this->withHeaders([
        'Authorization' => 'Bearer server-token',
        'Accept' => 'application/json',
    ])
        ->postJson('/api/v1/server-history', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(ServerInformationHistory::query()->where('server_id', $server->id)->exists())->toBeFalse();
})->with([
    'negative cpu load' => [['cpu_load' => '-0.01'], 'cpu_load'],
    'non numeric cpu load' => [['cpu_load' => 'not-a-load'], 'cpu_load'],
    'ram percentage above 100' => [['ram_free_percentage' => '101'], 'ram_free_percentage'],
    'negative ram free' => [['ram_free' => '-1'], 'ram_free'],
    'disk percentage below 0' => [['disk_free_percentage' => '-1'], 'disk_free_percentage'],
    'negative disk free bytes' => [['disk_free_bytes' => '-1'], 'disk_free_bytes'],
    'zero cpu cores' => [['cpu_cores' => 0], 'cpu_cores'],
    'invalid server id' => [['s' => 'server-1'], 's'],
]);
