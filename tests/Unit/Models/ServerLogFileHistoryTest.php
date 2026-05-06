<?php

use App\Models\ServerLogFileHistory;

test('copy command downloads and schedules the same log reporter filename', function () {
    $user = $this->actingAsSuperAdmin();
    $serverId = 123;

    $command = ServerLogFileHistory::copyCommand($serverId);

    expect($command)->toContain('wget');
    expect($command)->toContain("log-reporter/$serverId/{$user->id}");
    expect($command)->toContain('-O log_reporter_server_info.sh');
    expect($command)->toContain('chmod +x $(pwd)/log_reporter_server_info.sh');
    expect($command)->toContain('0 * * * * $(pwd)/log_reporter_server_info.sh');
    expect($command)->not->toContain('log-reporter_server_info.sh');
});
