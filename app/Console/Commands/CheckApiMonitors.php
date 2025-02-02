<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use Illuminate\Console\Command;
use App\Models\MonitorApiResult;
use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
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
                    'url' => $monitor->url
                ]);

                MonitorApiResult::recordResult($monitor, $result, $startTime);
                $count++;

                // If there's an error (HTTP code not 200) or any assertion is not met, send a notification
                if ($result['code'] != 200 || (isset($result['assertions']) && count(array_filter($result['assertions'], function ($assertion) {
                    return !$assertion['passed'];
                })) > 0)) {
                    $message = "API Monitor Alert for {$monitor->title} ({$monitor->url}): ";
                    if ($result['code'] != 200) {
                        $message .= "HTTP Code: {$result['code']}. ";
                    }
                    if (isset($result['assertions'])) {
                        $failed = array_filter($result['assertions'], function ($a) {
                            return !$a['passed'];
                        });
                        foreach ($failed as $assertion) {
                            $message .= "Assertion failed at {$assertion['path']}: {$assertion['message']}; ";
                        }
                    }
                    // Retrieve global notification channels for API monitors for the monitor's user
                    $globalChannels = $monitor->user->globalNotificationChannels()
                        ->whereIn('inspection', [WebsiteServicesEnum::API_MONITOR->name, WebsiteServicesEnum::ALL_CHECK->name])
                        ->get();

                    Log::info("Retrieved notification channels", [
                        'monitor_id' => $monitor->id,
                        'user_id' => $monitor->user->id,
                        'channel_count' => $globalChannels->count(),
                        'channels' => $globalChannels->pluck('id')->toArray()
                    ]);

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
