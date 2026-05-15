<?php

namespace App\Console\Commands;

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class LogJobCheckUptimeSsl extends Command
{
    public const SUPPORTED_INTERVALS = [1, 5, 10, 15, 30, 60, 360, 720, 1440];

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

    protected array $intervals = self::SUPPORTED_INTERVALS;

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
                    ->whereDoesntHave('latestScheduledLogHistory')
                    ->orWhereHas('latestScheduledLogHistory', function (Builder $query): void {
                        $query->whereRaw($this->dueAtExpression(), [now()->startOfMinute()->toDateTimeString()]);
                    });
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
            'sqlite' => "datetime(strftime('%Y-%m-%d %H:%M:00', website_log_history.created_at), '+' || websites.uptime_interval || ' minutes') <= ?",
            'pgsql' => "date_trunc('minute', website_log_history.created_at) + (websites.uptime_interval * interval '1 minute') <= ?",
            'sqlsrv' => 'DATEADD(minute, websites.uptime_interval, DATEADD(minute, DATEDIFF(minute, 0, website_log_history.created_at), 0)) <= ?',
            default => "DATE_ADD(DATE_FORMAT(website_log_history.created_at, '%Y-%m-%d %H:%i:00'), INTERVAL websites.uptime_interval MINUTE) <= ?",
        };
    }
}
