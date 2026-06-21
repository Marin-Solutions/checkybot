<?php

use App\Mail\HealthStatusAlert;
use App\Models\Backup;
use App\Models\BackupHistory;
use App\Models\BackupIntervalOption;
use App\Models\NotificationSetting;
use App\Models\Server;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

test('command marks backups stale when no run arrives after the configured interval', function () {
    Mail::fake();

    $server = Server::factory()->create();
    $interval = BackupIntervalOption::query()->create([
        'value' => 1,
        'unit' => 'day',
        'expression' => '0 2 * * *',
    ]);
    $backup = Backup::query()->create([
        'server_id' => $server->id,
        'dir_path' => '/var/www/html',
        'remote_storage_id' => 1,
        'remote_storage_path' => '/',
        'interval_id' => (string) $interval->id,
        'first_run_at' => now()->subDays(3),
        'compression_type' => 'zip',
    ]);
    $backup->forceFill(['last_history_at' => now()->subDays(2)])->save();

    BackupHistory::query()->create([
        'backup_id' => $backup->id,
        'filename' => 'site-backup.zip',
        'filesize' => 2048,
        'is_zipped' => true,
        'is_uploaded' => true,
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $server->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::BACKUP_MONITOR,
            'address' => 'ops@example.com',
        ]);

    $this->artisan('backups:mark-stale')
        ->assertSuccessful();

    expect($backup->refresh()->stale_at)->not->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, function (HealthStatusAlert $mail): bool {
        return $mail->event === 'backup_missed'
            && $mail->status === 'danger'
            && str_contains($mail->summary, 'No backup run has reported');
    });
});

test('command does not duplicate missed backup alerts while stale state is active', function () {
    Mail::fake();

    $server = Server::factory()->create();
    $interval = BackupIntervalOption::query()->create([
        'value' => 1,
        'unit' => 'hour',
        'expression' => '0 * * * *',
    ]);
    $backup = Backup::query()->create([
        'server_id' => $server->id,
        'dir_path' => '/var/www/html',
        'remote_storage_id' => 1,
        'remote_storage_path' => '/',
        'interval_id' => (string) $interval->id,
        'first_run_at' => now()->subHours(3),
        'compression_type' => 'zip',
    ]);
    $backup->forceFill(['stale_at' => now()->subHour()])->save();

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $server->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::BACKUP_MONITOR,
            'address' => 'ops@example.com',
        ]);

    $this->artisan('backups:mark-stale')
        ->assertSuccessful();

    expect($backup->refresh()->stale_at)->not->toBeNull();
    Mail::assertNothingSent();
});

test('scheduled backup stale detection uses overlap protection', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->command, 'backups:mark-stale')
    );

    expect($event)->not->toBeNull();
    expect($event->withoutOverlapping)->toBeTrue();
});
