<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\MonitorApiResult;
use Illuminate\Console\Command;
use App\Enums\WebsiteServicesEnum;

class CheckApiMonitors extends Command
{
    protected $signature = 'monitor:check-apis';
    protected $description = 'Check API monitors and send notifications if necessary';

    public function handle()
    {
        // Retrieve all API monitors along with the associated user.
        $monitors = MonitorApis::with('user')->get();

        foreach ($monitors as $monitor) {
            $startTime = microtime(true);
            $result = MonitorApis::testApi([
                'id' => $monitor->id,
                'url' => $monitor->url,
                'data_path' => $monitor->data_path,
            ]);

            // Record the API test result.
            MonitorApiResult::recordResult($monitor, $result, $startTime);

            // If the API response code is not 200 or any assertion fails, send notifications.
            if (
                $result['code'] != 200 ||
                (isset($result['assertions']) && count(array_filter($result['assertions'], function ($a) {
                    return !$a['passed'];
                })) > 0)
            ) {
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

                // Retrieve global notification settings for the API monitor.
                $globalChannels = $monitor->user->globalNotificationChannels()
                    ->whereIn('inspection', [
                        WebsiteServicesEnum::API_MONITOR->name,
                        WebsiteServicesEnum::ALL_CHECK->name
                    ])
                    ->get();

                // Send notifications using the related notification channel.
                foreach ($globalChannels as $channelSetting) {
                    if ($channelSetting->channel) {
                        $channelSetting->channel->sendWebhookNotification([
                            'message' => $message,
                            'description' => "API Monitor Error Notification"
                        ]);
                    }
                }
            }
        }
    }
}
