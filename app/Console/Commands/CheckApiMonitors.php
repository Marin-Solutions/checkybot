<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\MonitorApiResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckApiMonitors extends Command
{
    protected $signature = 'monitor:check-apis';
    protected $description = 'Check all API monitors and record their results';

    public function handle()
    {
        $this->info('Starting API monitoring checks...');
        $monitors = MonitorApis::with(['assertions', 'user'])->get();
        $count = 0;

        foreach ($monitors as $monitor) {
            try {
                $startTime = microtime(true);
                $result = MonitorApis::testApi([
                    'id' => $monitor->id,
                    'url' => $monitor->url,
                    'data_path' => $monitor->data_path
                ]);

                MonitorApiResult::recordResult($monitor, $result, $startTime);
                $count++;

                // Always check if the data path exists and has a value
                $shouldNotify = false;
                $message = "API Monitor Alert for {$monitor->title} ({$monitor->url}): ";

                if ($result['code'] != 200) {
                    $shouldNotify = true;
                    $message .= "HTTP Code: {$result['code']}. ";
                }

                // Check data path if specified
                if ($monitor->data_path) {
                    $data = json_decode($result['body'], true);
                    $value = data_get($data, $monitor->data_path);

                    if ($value === null) {
                        $shouldNotify = true;
                        $message .= "Data path '{$monitor->data_path}' not found or null in response. ";
                    }
                }

                // Check assertions if any
                if (isset($result['assertions']) && count(array_filter($result['assertions'], function ($assertion) {
                    return !$assertion['passed'];
                })) > 0) {
                    $shouldNotify = true;
                    $failed = array_filter($result['assertions'], function ($a) {
                        return !$a['passed'];
                    });
                    foreach ($failed as $assertion) {
                        $message .= "Assertion failed at {$assertion['path']}: {$assertion['message']}; ";
                    }
                }

                // Send notification if any check failed
                if ($shouldNotify) {
                    $globalChannels = $monitor->user->globalNotificationChannels()
                        ->whereIn('inspection', ['API_MONITOR', 'ALL_CHECK'])
                        ->get();

                    foreach ($globalChannels as $notificationSetting) {
                        $channel = $notificationSetting->channel;
                        if (!$channel) {
                            Log::warning("No channel found for notification setting", [
                                'setting_id' => $notificationSetting->id
                            ]);
                            continue;
                        }

                        Log::info("Attempting to send notification", [
                            'channel_id' => $channel->id,
                            'channel_url' => $channel->url,
                            'channel_method' => $channel->method
                        ]);

                        $result = $channel->sendWebhookNotification([
                            'message' => $message,
                            'description' => "API Monitor Error Notification"
                        ]);

                        Log::info("Webhook notification attempt result", [
                            'channel_id' => $channel->id,
                            'result' => $result
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error checking monitor {$monitor->title}: " . $e->getMessage());
            }
        }

        $this->info("Completed checking {$count} API monitors.");
    }
}
