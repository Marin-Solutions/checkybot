<?php

namespace App\Console\Commands;

use App\Models\ServerInformationHistory;
use Illuminate\Console\Command;

class PurgeServerLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:purge-server-logs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // For the last 24 hours we will keep the data of every minute
        // For data older than 24 hours but younger than 7 days we will keep the data of every ten minutes
        // For data older than 7 days we will keep the data of every hour

        // get the data older than 24 hours and younger than 7 days
        $dataOlderThan24Hours = ServerInformationHistory::query()
            ->where('created_at', '<', now()->subHours(24))
            ->where('created_at', '>=', now()->subDays(7));

        // Now delete the data where the minute is not multiple of 10
        $dataOlderThan24Hours->where(function ($query) {
            $query->where('minute', '0')
                ->orWhere('minute', '10')
                ->orWhere('minute', '20')
                ->orWhere('minute', '30')
                ->orWhere('minute', '40')
                ->orWhere('minute', '50');
        });

        $dataOlderThan24Hours->delete();

        // get the data older than 7 days
        $dataOlderThan7Days = ServerInformationHistory::query()
            ->where('created_at', '<', now()->subDays(7));

        // Now delete the data where minute is not 00
        $dataOlderThan7Days->where('minute', '!=', '00');

        $dataOlderThan7Days->delete();
    }
}
