<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\MonitorApiResult;
use Illuminate\Console\Command;

class CheckApiMonitors extends Command
{
    protected $signature = 'monitor:check-apis';
    protected $description = 'Check all API monitors and record their results';

    public function handle()
    {
        $this->info('Starting API monitoring checks...');

        $monitors = MonitorApis::with('assertions')->get();
        $count = 0;

        foreach ($monitors as $monitor) {
            try {
                $result = MonitorApis::testApi([
                    'id' => $monitor->id,
                    'url' => $monitor->url
                ]);

                MonitorApiResult::recordResult($monitor, $result);
                $count++;
            } catch (\Exception $e) {
                $this->error("Error checking monitor {$monitor->title}: " . $e->getMessage());
            }
        }

        $this->info("Completed checking {$count} API monitors.");
    }
}
