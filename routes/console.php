<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();


Schedule::command('ssl:check')->everyMinute();
Schedule::command('website:log-uptime-ssl')->everyMinute();
Schedule::command('server:purge-logs')->everyMinute();
Schedule::command('website:scan-outbound-check')->daily();
Schedule::command('telescope:prune --hours=24')->hourly();
