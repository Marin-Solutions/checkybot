<?php

use App\Models\ServerInformationHistory;

test('server information history has fillable attributes', function () {
    $history = ServerInformationHistory::factory()->create([
        'cpu_load' => 2.5,
        'ram_free_percentage' => 45.5,
        'ram_free' => 4096,
        'disk_free_percentage' => 60.0,
        'disk_free_bytes' => 102400,
    ]);

    expect($history->server_id)->not->toBeNull();
    expect($history->cpu_load)->toBe(2.5);
    expect($history->ram_free_percentage)->toBe(45.5);
    expect($history->ram_free)->toBe(4096);
    expect($history->disk_free_percentage)->toBe(60.0);
    expect($history->disk_free_bytes)->toBe(102400);
});

test('server information history casts dates', function () {
    $history = ServerInformationHistory::factory()->create();

    expect($history->created_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($history->updated_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('server information history uses correct table', function () {
    $history = new ServerInformationHistory;

    expect($history->getTable())->toBe('server_information_history');
});

test('is valid token returns true', function () {
    expect(ServerInformationHistory::isValidToken())->toBeTrue();
});

test('copy command generates wget command', function () {
    $user = $this->actingAsSuperAdmin();
    $serverId = 123;

    $command = ServerInformationHistory::copyCommand($serverId);

    expect($command)->toContain('wget');
    expect($command)->toContain("reporter/$serverId/{$user->id}");
    expect($command)->toContain('reporter_server_info.sh');
    expect($command)->toContain('chmod +x');
    expect($command)->toContain('crontab');
});

test('copy command includes cron setup', function () {
    $user = $this->actingAsSuperAdmin();
    $serverId = 456;

    $command = ServerInformationHistory::copyCommand($serverId);

    expect($command)->toContain('CRON_CMD=');
    expect($command)->toContain('crontab -l');
    expect($command)->toContain('*/1 * * * *');
});
