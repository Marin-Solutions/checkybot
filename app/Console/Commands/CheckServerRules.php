<?php

namespace App\Console\Commands;

use App\Models\NotificationChannels;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerRule;
use App\Traits\ChecksWebhookResponses;
use Illuminate\Console\Command;

class CheckServerRules extends Command
{
    use ChecksWebhookResponses;

    private const REPORTER_FRESHNESS_MINUTES = 5;

    protected $signature = 'server:check-rules';

    protected $description = 'Check server monitoring rules and send notifications if conditions are met';

    public function handle(): int
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

                if (! $latestInfo) {
                    $this->skipRuleEvaluation(
                        $rule,
                        'skipped_missing_reporter',
                        'No reporter data has been received for this server.',
                    );

                    $this->warn("Skipped server rule for {$rule->server->name}: no reporter data has been received");

                    continue;
                }

                if ($latestInfo->created_at->lt(now()->subMinutes(self::REPORTER_FRESHNESS_MINUTES))) {
                    $this->skipRuleEvaluation(
                        $rule,
                        'skipped_stale_reporter',
                        'Latest reporter data is stale; waiting for a fresh sample before evaluating this rule.',
                        $latestInfo->created_at,
                    );

                    $this->warn("Skipped server rule for {$rule->server->name}: latest reporter data is stale from {$latestInfo->created_at->toDateTimeString()}");

                    continue;
                }

                $currentValue = $this->getCurrentValue($latestInfo, $rule->metric, $rule->server);

                if ($currentValue === null) {
                    $this->warn("Could not get current value for {$rule->metric} on server {$rule->server->name}");

                    continue;
                }

                $conditionIsMet = $this->isConditionMet($currentValue, $rule->operator, $rule->value);
                $evaluatedAt = now();
                $stateUpdates = [
                    'last_evaluated_value' => $currentValue,
                    'last_evaluated_at' => $evaluatedAt,
                    'last_evaluation_status' => 'evaluated',
                    'last_evaluation_reason' => null,
                    'last_reported_at' => $latestInfo->created_at,
                ];

                if ($conditionIsMet && ! $rule->is_triggered) {
                    $this->info("Rule condition met for server {$rule->server->name}: {$rule->metric} = {$currentValue}");

                    if ($this->sendNotification($rule->server, $rule, $currentValue, 'alert')) {
                        $rule->forceFill($stateUpdates + [
                            'is_triggered' => true,
                            'triggered_at' => $evaluatedAt,
                            'recovered_at' => null,
                        ])->save();
                    } else {
                        $rule->forceFill($stateUpdates)->save();
                    }
                } elseif (! $conditionIsMet && $rule->is_triggered) {
                    $this->info("Rule recovered for server {$rule->server->name}: {$rule->metric} = {$currentValue}");

                    if ($this->sendNotification($rule->server, $rule, $currentValue, 'recovery')) {
                        $rule->forceFill($stateUpdates + [
                            'is_triggered' => false,
                            'recovered_at' => $evaluatedAt,
                        ])->save();
                    } else {
                        $rule->forceFill($stateUpdates)->save();
                    }
                } else {
                    $rule->forceFill($stateUpdates)->save();
                }
            } catch (\Exception $e) {
                $this->error('Error processing rule: '.$e->getMessage());

                continue;
            }
        }

        return Command::SUCCESS;
    }

    private function skipRuleEvaluation(ServerRule $rule, string $status, string $reason, $reportedAt = null): void
    {
        $rule->forceFill([
            'last_evaluated_value' => null,
            'last_evaluated_at' => now(),
            'last_evaluation_status' => $status,
            'last_evaluation_reason' => $reason,
            'last_reported_at' => $reportedAt,
        ])->save();
    }

    private function getCurrentValue($latestInfo, $metric, Server $server)
    {
        return match ($metric) {
            'cpu_usage' => $server->cpuLoadToUsagePercentage($latestInfo->cpu_load),
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

    private function sendNotification($server, $rule, $currentValue, string $eventType): bool
    {
        try {
            $channel = NotificationChannels::query()
                ->where('created_by', $server->created_by)
                ->find($rule->channel);
            if (! $channel) {
                $this->warn("Notification channel not found for rule on server {$server->name}");

                return false;
            }

            $metric = ucfirst(str_replace('_', ' ', $rule->metric));
            $description = $eventType === 'recovery'
                ? 'Server Monitoring Recovery'
                : 'Server Monitoring Alert';
            $message = $eventType === 'recovery'
                ? "Recovery for {$server->name} ({$server->ip})\n{$metric} is back to {$currentValue}% (threshold: {$rule->operator} {$rule->value}%)"
                : "Alert for {$server->name} ({$server->ip})\n{$metric} is {$currentValue}% {$rule->operator} {$rule->value}%";

            $response = $channel->sendWebhookNotification([
                'message' => $message,
                'description' => $description,
            ], $eventType === 'recovery' ? 'server_rule_recovery' : 'send');

            if (! $this->webhookResponseWasSuccessful($response)) {
                $code = (int) ($response['code'] ?? 0);

                $this->error("Webhook {$eventType} notification failed for server {$server->name} with response code {$code}");

                return false;
            }

            $this->info(ucfirst($eventType)." notification sent for server {$server->name}");

            return true;
        } catch (\Exception $e) {
            $this->error('Failed to send notification: '.$e->getMessage());

            return false;
        }
    }
}
