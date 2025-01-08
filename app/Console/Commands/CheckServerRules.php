<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerRule;
use App\Models\NotificationChannels;
use App\Models\ServerInformationHistory;
use Illuminate\Console\Command;

class CheckServerRules extends Command
{
    protected $signature = 'server:check-rules';
    protected $description = 'Check server monitoring rules and send notifications if conditions are met';

    public function handle()
    {
        // Get all active rules first
        $rules = ServerRule::with(['server'])
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            try {
                // Get the latest information for this server
                $latestInfo = ServerInformationHistory::where('server_id', $rule->server_id)
                    ->latest()
                    ->first();

                if (!$latestInfo) {
                    $this->warn("No information history for server {$rule->server->name}");
                    continue;
                }

                $currentValue = $this->getCurrentValue($latestInfo, $rule->metric);
                
                if ($currentValue === null) {
                    $this->warn("Could not get current value for {$rule->metric} on server {$rule->server->name}");
                    continue;
                }

                if ($this->isConditionMet($currentValue, $rule->operator, $rule->value)) {
                    $this->info("Rule condition met for server {$rule->server->name}: {$rule->metric} = {$currentValue}");
                    $this->sendNotification($rule->server, $rule, $currentValue);
                }
            } catch (\Exception $e) {
                $this->error("Error processing rule: " . $e->getMessage());
                continue;
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