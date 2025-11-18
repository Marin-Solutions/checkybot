<?php

namespace App\Console\Commands;

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\Website;
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
    public function handle()
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
        $days = [14, 7, 3, 2, 1];
        $now = Carbon::today();

        $websites = Website::where('ssl_check', '1')
            ->get()
            ->filter(function (Website $website) use ($days, $now) {
                if ($website->ssl_expiry_date === null) {
                    return true;
                }

                $expiryDate = Carbon::parse($website->ssl_expiry_date);
                $diffInDays = $now->diffInDays($expiryDate, false);

                return in_array($diffInDays, $days) || $diffInDays < 0;
            })
            ->values();

        return $websites;
    }
}
