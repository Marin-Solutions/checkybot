<?php

namespace App\Console\Commands;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelFlare\Facades\Flare;

class CheckApiMonitors extends Command
{
    protected $signature = 'monitor:check-apis';

    protected $description = 'Check all API monitors and record their results';

    public function handle()
    {
        $this->info('Starting API monitor checks...');
        $monitors = MonitorApis::all();
        $count = 0;

        foreach ($monitors as $monitor) {
            try {
                $startTime = microtime(true);
                $result = MonitorApis::testApi([
                    'id' => $monitor->id,
                    'url' => $monitor->url,
                    'data_path' => $monitor->data_path,
                    'headers' => $monitor->headers,
                    'title' => $monitor->title,
                ]);

                if (! isset($result['code'])) {
                    Flare::context('monitor_id', $monitor->id);
                    Flare::context('monitor_title', $monitor->title);
                    Flare::context('url', $monitor->url);
                    Flare::context('data_path', $monitor->data_path);
                    Flare::context('result', $result);
                    throw new \Exception('Invalid API test result format - missing code');
                }

                MonitorApiResult::recordResult($monitor, $result, $startTime);
                $count++;

                $shouldNotify = false;
                $message = "API Monitor Alert for {$monitor->title}: ";

                if (isset($result['error']) && $result['error']) {
                    $shouldNotify = true;
                    $message .= $result['error'].' ';
                } elseif ($result['code'] != 200) {
                    $shouldNotify = true;
                    $message .= "HTTP Code: {$result['code']}. ";
                }

                if ($monitor->data_path) {
                    $data = is_string($result['body']) ? json_decode($result['body'], true) : $result['body'];
                    if (! Arr::has($data, $monitor->data_path)) {
                        $shouldNotify = true;
                        $message .= "Specified key '{$monitor->data_path}' not found in response. ";
                    } else {
                        if (isset($result['assertions']) && count(array_filter($result['assertions'], function ($assertion) {
                            return ! $assertion['passed'];
                        })) > 0) {
                            $shouldNotify = true;
                            $failed = array_filter($result['assertions'], function ($a) {
                                return ! $a['passed'];
                            });
                            foreach ($failed as $assertion) {
                                $message .= "Assertion failed at {$assertion['path']}: {$assertion['message']}; ";
                            }
                        }
                    }
                } else {
                    if (isset($result['assertions']) && count(array_filter($result['assertions'], function ($assertion) {
                        return ! $assertion['passed'];
                    })) > 0) {
                        $shouldNotify = true;
                        $failed = array_filter($result['assertions'], function ($a) {
                            return ! $a['passed'];
                        });
                        foreach ($failed as $assertion) {
                            $message .= "Assertion failed at {$assertion['path']}: {$assertion['message']}; ";
                        }
                    }
                }

                if ($shouldNotify) {
                    $globalChannels = $monitor->user->globalNotificationChannels()
                        ->whereIn('inspection', ['API_MONITOR', 'ALL_CHECK'])
                        ->get();

                    foreach ($globalChannels as $notificationSetting) {
                        $channel = $notificationSetting->channel;
                        if (! $channel) {
                            Log::warning('No channel found for notification setting', [
                                'setting_id' => $notificationSetting->id,
                            ]);

                            continue;
                        }

                        Log::info('Attempting to send notification', [
                            'channel_id' => $channel->id,
                            'channel_url' => $channel->url,
                            'channel_method' => $channel->method,
                        ]);

                        $result = $channel->sendWebhookNotification([
                            'message' => $message,
                            'description' => 'API Monitor Error Notification',
                        ]);

                        Log::info('Webhook notification attempt result', [
                            'channel_id' => $channel->id,
                            'result' => $result,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
        $this->info("Completed checking {$count} API monitors.");
    }
}
