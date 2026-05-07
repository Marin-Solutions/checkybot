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
    expect($command)->toContain('CRON_CMD="$(pwd)/log_reporter_server_info.sh"');
    expect($command)->toContain('CRON_ENTRY="0 * * * * $CRON_CMD"');
    expect($command)->not->toContain('log-reporter_server_info.sh');
});

test('copy command installs hourly cron idempotently', function () {
    $this->actingAsSuperAdmin();

    $command = ServerLogFileHistory::copyCommand(123);

    expect($command)->toContain('crontab -l 2>/dev/null | grep -Fqx "$CRON_ENTRY"');
    expect($command)->toContain('(crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -');
});

test('content shell script escapes configured log paths and names', function () {
    config(['app.url' => 'https://checkybot.test/app path']);

    $history = new ServerLogFileHistory;
    $history->server_id = 123;
    $history->token = "tok'en";

    $logName = "prod app'; touch /tmp/pwn #";
    $logDirectory = "/var/log/prod app'; rm -rf / #.log";
    $tmpLog = '/tmp/'.$logName.'_log.log';

    $method = new ReflectionMethod(ServerLogFileHistory::class, 'contentShellScript');
    $method->setAccessible(true);

    $script = $method->invoke($history, [[
        'id' => 77,
        'name' => $logName,
        'log_directory' => $logDirectory,
    ]]);

    expect($script)->toContain('API_LOG_URL='.escapeshellarg('https://checkybot.test/app path/api/v1/server-log-history'));
    expect($script)->toContain('TOKEN_ID='.escapeshellarg("tok'en"));
    expect($script)->toContain('SERVER_ID='.escapeshellarg('123'));
    expect($script)->toContain('tail -c 2097152 '.escapeshellarg($logDirectory).' > '.escapeshellarg($tmpLog));
    expect($script)->toContain(' -F '.escapeshellarg('log=@'.$tmpLog));
    expect($script)->toContain(' -F '.escapeshellarg('li=77'));
    expect($script)->toContain('rm '.escapeshellarg($tmpLog));
});
