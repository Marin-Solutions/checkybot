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
    public function handle(): void
    {
        // For the last 24 hours we will keep the data of every minute
        // For data older than 24 hours but younger than 7 days we will keep the data of every ten minutes
        // For data older than 7 days we will keep the data of every hour

        // Delete data older than 24 hours and younger than 7 days where minute is NOT a multiple of 10
        $midRangeRecords = ServerInformationHistory::query()
            ->where('created_at', '<', now()->subHours(24))
            ->where('created_at', '>=', now()->subDays(7))
            ->get();

        $deletedMidRange = 0;
        foreach ($midRangeRecords as $record) {
            $minute = (int) $record->created_at->format('i');
            if (! in_array($minute, [0, 10, 20, 30, 40, 50])) {
                $record->delete();
                $deletedMidRange++;
            }
        }

        // Delete data older than 7 days where minute is NOT 00
        $oldRecords = ServerInformationHistory::query()
            ->where('created_at', '<', now()->subDays(7))
            ->get();

        $deletedOld = 0;
        foreach ($oldRecords as $record) {
            $minute = (int) $record->created_at->format('i');
            if ($minute !== 0) {
                $record->delete();
                $deletedOld++;
            }
        }

        $this->comment("Purged {$deletedMidRange} mid-range logs and {$deletedOld} old logs.");
    }
}
