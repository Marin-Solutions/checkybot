<?php

namespace App\Console\Commands;

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use Illuminate\Console\Command;

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
            ->chunkById(100, function ($websites) use (&$count): void {
                $websites
                    ->filter(fn (Website $website): bool => $this->isDue($website))
                    ->each(function (Website $website) use (&$count): void {
                        LogUptimeSslJob::dispatch($website)->onQueue('log-website');
                        $count++;
                    });
            });

        if ($count > 0) {
            $this->info('Processing '.$count.' websites due for uptime checks.');
        }

        return Command::SUCCESS;
    }

    private function isDue(Website $website): bool
    {
        if ($website->last_heartbeat_at === null) {
            return true;
        }

        return $website->last_heartbeat_at
            ->copy()
            ->startOfMinute()
            ->addMinutes((int) $website->uptime_interval)
            ->lte(now()->startOfMinute());
    }
}
