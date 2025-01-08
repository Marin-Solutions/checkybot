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
        $servers = Server::with([
            'rules' => function ($query) {
                $query->where('is_active', true);
            },
            'informationHistory' => function ($query) {
                $query->latest()->limit(1);
            }
        ])->get();

        foreach ($servers as $server) {
            // Skip if no information history exists
            if ($server->informationHistory->isEmpty()) {
                $this->warn("No information history for server {$server->name}");
                continue;
            }

            $latestInfo = $server->informationHistory->first();

            foreach ($server->rules as $rule) {
                try {
                    $currentValue = $this->getCurrentValue($latestInfo, $rule->metric);
                    
                    if ($currentValue === null) {
                        $this->warn("Could not get current value for {$rule->metric} on server {$server->name}");
                        continue;
                    }

                    if ($this->isConditionMet($currentValue, $rule->operator, $rule->value)) {
                        $this->info("Rule condition met for server {$server->name}: {$rule->metric} = {$currentValue}");
                        $this->sendNotification($server, $rule, $currentValue);
                    }
                } catch (\Exception $e) {
                    $this->error("Error processing rule for server {$server->name}: " . $e->getMessage());
                    continue;
                }
            }
        }
    }

    private function getCurrentValue($latestInfo, $metric)
    {
        return match ($metric) {
            'cpu_usage' => (float) str_replace(',', '.', $latestInfo->cpu_load),
            'ram_usage' => 100 - (float) str_replace(['%', ' '], '', $latestInfo->ram_free_percentage),
            'disk_usage' => 100 - (float) str_replace(['%', ' '], '', $latestInfo->disk_free_percentage),
            default => null,
        };
    }

    private function isConditionMet($currentValue, $operator, $threshold)
    {
        return match ($operator) {
            '>' => $currentValue > $threshold,
            '<' => $currentValue < $threshold,
            '=' => $currentValue == $threshold,
            default => false,
        };
    }

    private function sendNotification($server, $rule, $currentValue)
    {
        try {
            $channel = NotificationChannels::find($rule->channel);
            if (!$channel) {
                $this->warn("Notification channel not found for rule on server {$server->name}");
                return;
            }

            $message = "Alert for {$server->name} ({$server->ip})\n";
            $message .= ucfirst(str_replace('_', ' ', $rule->metric)) . " is {$currentValue}% {$rule->operator} {$rule->value}%";

            $channel->sendWebhookNotification([
                'message' => $message,
                'description' => "Server Monitoring Alert"
            ]);

            $this->info("Notification sent for server {$server->name}");
        } catch (\Exception $e) {
            $this->error("Failed to send notification: " . $e->getMessage());
        }
    }
} 