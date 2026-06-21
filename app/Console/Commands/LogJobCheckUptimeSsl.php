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
                $latestScheduledAtSql = $this->latestScheduledLogAtSql();

                $query
                    ->whereRaw("{$latestScheduledAtSql} is null")
                    ->orWhereRaw($this->dueAtExpression($latestScheduledAtSql), [now()->startOfMinute()->toDateTimeString()]);
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

    private function dueAtExpression(string $anchorSql): string
    {
        return match (Website::query()->getConnection()->getDriverName()) {
            'sqlite' => "datetime(strftime('%Y-%m-%d %H:%M:00', {$anchorSql}), '+' || websites.uptime_interval || ' minutes') <= ?",
            'pgsql' => "date_trunc('minute', {$anchorSql}) + (websites.uptime_interval * interval '1 minute') <= ?",
            'sqlsrv' => "DATEADD(minute, websites.uptime_interval, DATEADD(minute, DATEDIFF(minute, 0, {$anchorSql}), 0)) <= ?",
            default => "DATE_ADD(DATE_FORMAT({$anchorSql}, '%Y-%m-%d %H:%i:00'), INTERVAL websites.uptime_interval MINUTE) <= ?",
        };
    }

    private function latestScheduledLogAtSql(): string
    {
        return '(select max(website_log_history.created_at) from website_log_history where website_log_history.website_id = websites.id and '.$this->scheduledRunPredicate('website_log_history.is_on_demand').')';
    }

    private function scheduledRunPredicate(string $column): string
    {
        return match (Website::query()->getConnection()->getDriverName()) {
            'pgsql' => "({$column} is null or {$column} = false)",
            default => "({$column} is null or {$column} = 0)",
        };
    }
}
