<?php

namespace App\Console\Commands;

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class LogJobCheckUptimeSsl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'website:log-uptime-ssl';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Log website uptime and SSL';

    protected array $intervals = [1, 5, 10, 15, 30, 60, 360, 720, 1440];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        Website::query()
            ->where('uptime_check', true)
            ->whereIn('uptime_interval', $this->intervals)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('last_heartbeat_at')
                    ->orWhereRaw($this->dueAtExpression(), [now()->startOfMinute()->toDateTimeString()]);
            })
            ->chunkById(100, function ($websites) use (&$count): void {
                $websites->each(function (Website $website) use (&$count): void {
                    LogUptimeSslJob::dispatch($website)->onQueue('log-website');
                    $count++;
                });
            });

        if ($count > 0) {
            $this->info('Processing '.$count.' websites due for uptime checks.');
        }

        return Command::SUCCESS;
    }

    private function dueAtExpression(): string
    {
        return match (Website::query()->getConnection()->getDriverName()) {
            'sqlite' => "datetime(last_heartbeat_at, '+' || uptime_interval || ' minutes') <= ?",
            'pgsql' => "last_heartbeat_at + (uptime_interval * interval '1 minute') <= ?",
            'sqlsrv' => 'DATEADD(minute, uptime_interval, last_heartbeat_at) <= ?',
            default => 'DATE_ADD(last_heartbeat_at, INTERVAL uptime_interval MINUTE) <= ?',
        };
    }
}
