<?php

namespace App\Console\Commands;

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\Website;
use App\Services\IntervalParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class WriteJobCheckSsl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ssl:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check SSL certificates and send reminders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $websites = $this->sslExpiryDay();

        if ($websites->isNotEmpty()) {
            $websites->each(function ($website) {
                CheckSslExpiryDateJob::dispatch($website)->onQueue('ssl-check');
            });
        }

        $this->info('SSL check completed successfully.');

        return Command::SUCCESS;
    }

    protected function sslExpiryDay(): Collection
    {
        $days = [14, 7, 3, 2, 1, 0];
        $now = Carbon::today();

        $websites = Website::where('ssl_check', '1')
            ->get()
            ->filter(function (Website $website) use ($days, $now) {
                if ($this->packageSslCheckIsDue($website)) {
                    return true;
                }

                if ($this->isPackageSslOnlyCheck($website)) {
                    return false;
                }

                if ($website->ssl_expiry_date === null) {
                    return true;
                }

                $expiryDate = Carbon::parse($website->ssl_expiry_date);
                $diffInDays = $now->diffInDays($expiryDate, false);

                return in_array((int) $diffInDays, $days, true) || $diffInDays < 0;
            })
            ->values();

        return $websites;
    }

    private function packageSslCheckIsDue(Website $website): bool
    {
        if (! $this->isPackageSslOnlyCheck($website)) {
            return false;
        }

        if ($website->last_heartbeat_at === null) {
            return true;
        }

        try {
            return $website->last_heartbeat_at->lte(now()->subMinutes(IntervalParser::toMinutes($website->package_interval)));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function isPackageSslOnlyCheck(Website $website): bool
    {
        return $website->source === 'package'
            && ! $website->uptime_check
            && filled($website->package_interval);
    }
}
