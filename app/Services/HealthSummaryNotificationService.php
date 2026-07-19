<?php

namespace App\Services;

use App\Models\MonitorApis;
use App\Models\NotificationChannels;
use App\Models\ProjectComponent;
use App\Models\Website;
use App\Traits\ChecksWebhookResponses;
use Illuminate\Support\Collection;

class HealthSummaryNotificationService
{
    use ChecksWebhookResponses;

    private const ATTENTION_ITEM_LIMIT = 12;

    public function send(NotificationChannels $channel): bool
    {
        $payload = $this->payloadForUser($channel->created_by);
        $response = $channel->sendWebhookNotification($payload, 'health_summary');

        return $this->webhookResponseWasSuccessful($response);
    }

    /**
     * @return array{message: string, description: string}
     */
    public function payloadForUser(int $userId): array
    {
        $groups = $this->healthGroups($userId);
        $items = $groups->pluck('items')->flatten(1);
        $dangerCount = $items->where('status', 'danger')->count();
        $warningCount = $items->where('status', 'warning')->count();
        $pendingCount = $items->where('status', 'pending')->count();

        $description = $items->isEmpty()
            ? '⚪ No active monitors are configured.'
            : $this->summaryDescription($groups, $items);

        return [
            'message' => $this->headline($dangerCount, $warningCount, $pendingCount, $items->count()),
            'description' => $description,
        ];
    }

    /**
     * @return Collection<int, array{icon: string, label: string, items: Collection<int, array{name: string, type: string, status: string}>}>
     */
    private function healthGroups(int $userId): Collection
    {
        $apiChecks = MonitorApis::query()
            ->where('created_by', $userId)
            ->where('is_enabled', true)
            ->get(['title', 'current_status'])
            ->map(fn (MonitorApis $monitor): array => [
                'name' => $monitor->title,
                'type' => 'API',
                'status' => $this->normalizeStatus($monitor->current_status),
            ]);

        $websites = Website::query()
            ->where('created_by', $userId)
            ->where(function ($query): void {
                $query->where('uptime_check', true)->orWhere('ssl_check', true);
            })
            ->get(['name', 'current_status'])
            ->map(fn (Website $website): array => [
                'name' => $website->name,
                'type' => 'Website',
                'status' => $this->normalizeStatus($website->current_status),
            ]);

        $components = ProjectComponent::query()
            ->where('created_by', $userId)
            ->where('is_archived', false)
            ->with([
                'project:id,name',
                'activeMonitorApis:id,project_component_id,current_status',
                'activeWebsites:id,project_component_id,current_status,uptime_check,ssl_check',
            ])
            ->get()
            ->map(fn (ProjectComponent $component): array => [
                'name' => ($component->project?->name ? $component->project->name.' / ' : '').$component->name,
                'type' => 'Component',
                'status' => $this->normalizeStatus($component->derivedCurrentStatus()),
            ]);

        return collect([
            ['icon' => '🔌', 'label' => 'API checks', 'items' => $apiChecks],
            ['icon' => '🌐', 'label' => 'Websites', 'items' => $websites],
            ['icon' => '🧩', 'label' => 'Components', 'items' => $components],
        ]);
    }

    private function normalizeStatus(?string $status): string
    {
        return in_array($status, ['healthy', 'warning', 'danger'], true)
            ? $status
            : 'pending';
    }

    private function headline(int $dangerCount, int $warningCount, int $pendingCount, int $totalCount): string
    {
        if ($dangerCount > 0) {
            return "📊 Checkybot summary — 🔴 {$dangerCount} danger · 🟡 {$warningCount} warning".($warningCount === 1 ? '' : 's');
        }

        if ($warningCount > 0) {
            return "📊 Checkybot summary — 🟡 {$warningCount} warning".($warningCount === 1 ? '' : 's');
        }

        if ($pendingCount > 0) {
            return "📊 Checkybot summary — ⚪ {$pendingCount} pending";
        }

        if ($totalCount === 0) {
            return '📊 Checkybot summary — ⚪ no active monitors';
        }

        return '📊 Checkybot summary — ✅ all clear';
    }

    /**
     * @param  Collection<int, array{icon: string, label: string, items: Collection<int, array{name: string, type: string, status: string}>}>  $groups
     * @param  Collection<int, array{name: string, type: string, status: string}>  $items
     */
    private function summaryDescription(Collection $groups, Collection $items): string
    {
        $lines = $groups
            ->filter(fn (array $group): bool => $group['items']->isNotEmpty())
            ->map(fn (array $group): string => $this->groupSummary($group))
            ->values();

        $attentionItems = $items
            ->whereIn('status', ['danger', 'warning'])
            ->sortBy(fn (array $item): int => $item['status'] === 'danger' ? 0 : 1)
            ->values();

        if ($attentionItems->isNotEmpty()) {
            $lines->push('', 'Needs attention:');

            $attentionItems
                ->take(self::ATTENTION_ITEM_LIMIT)
                ->each(function (array $item) use ($lines): void {
                    $emoji = $item['status'] === 'danger' ? '🔴' : '🟡';
                    $lines->push("{$emoji} {$item['type']} — {$item['name']}");
                });

            $remaining = $attentionItems->count() - self::ATTENTION_ITEM_LIMIT;

            if ($remaining > 0) {
                $lines->push("…and {$remaining} more");
            }
        }

        return $lines->implode("\n");
    }

    /**
     * @param  array{icon: string, label: string, items: Collection<int, array{name: string, type: string, status: string}>}  $group
     */
    private function groupSummary(array $group): string
    {
        $items = $group['items'];

        return "{$group['icon']} {$group['label']}: "
            .'✅ '.$items->where('status', 'healthy')->count()
            .' · 🟡 '.$items->where('status', 'warning')->count()
            .' · 🔴 '.$items->where('status', 'danger')->count()
            .' · ⚪ '.$items->where('status', 'pending')->count();
    }
}
