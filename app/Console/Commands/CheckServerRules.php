<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerRule;
use App\Models\NotificationChannels;
use Illuminate\Console\Command;

class CheckServerRules extends Command
{
    protected $signature = 'server:check-rules';
    protected $description = 'Check server monitoring rules and send notifications if conditions are met';

    public function handle()
    {
        $servers = Server::with(['rules', 'informationHistory' => function ($query) {
            $query->latest()->take(1);
        }])->get();

        foreach ($servers as $server) {
            $latestInfo = $server->informationHistory->first();
            if (!$latestInfo) continue;

            foreach ($server->rules as $rule) {
                if (!$rule->is_active) continue;

                $currentValue = match ($rule->metric) {
                    'cpu_usage' => (float) str_replace(',', '.', $latestInfo->cpu_load),
                    'ram_usage' => 100 - (float) str_replace(['%', ' '], '', $latestInfo->ram_free_percentage),
                    'disk_usage' => 100 - (float) str_replace(['%', ' '], '', $latestInfo->disk_free_percentage),
                    default => null,
                };

                if ($currentValue === null) continue;

                $conditionMet = match ($rule->operator) {
                    '>' => $currentValue > $rule->value,
                    '<' => $currentValue < $rule->value,
                    '=' => $currentValue == $rule->value,
                    default => false,
                };

                if ($conditionMet) {
                    $this->sendNotification($server, $rule, $currentValue);
                }
            }
        }
    }

    private function sendNotification($server, $rule, $currentValue)
    {
        $channel = NotificationChannels::find($rule->channel);
        if (!$channel) return;

        $message = "Alert for {$server->name} ({$server->ip})\n";
        $message .= "{$rule->metric} is {$currentValue}% {$rule->operator} {$rule->value}%";

        $channel->sendWebhookNotification([
            'message' => $message,
            'description' => "Server Monitoring Alert"
        ]);
    }
} 