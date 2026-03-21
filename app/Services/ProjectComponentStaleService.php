<?php

namespace App\Services;

use App\Models\ProjectComponent;

class ProjectComponentStaleService
{
    public function __construct(
        protected ProjectComponentNotificationService $projectComponentNotificationService
    ) {}

    public function markStaleComponents(): int
    {
        $count = 0;

        $components = ProjectComponent::query()
            ->where('is_archived', false)
            ->where('is_stale', false)
            ->whereNotNull('last_heartbeat_at')
            ->with('project')
            ->get();

        foreach ($components as $component) {
            if (! $this->isOverdue($component)) {
                continue;
            }

            $component->forceFill([
                'current_status' => 'danger',
                'summary' => 'Heartbeat expired',
                'is_stale' => true,
                'stale_detected_at' => now(),
            ])->save();

            $component->heartbeats()->create([
                'component_name' => $component->name,
                'status' => 'danger',
                'event' => 'stale',
                'summary' => 'Heartbeat expired',
                'metrics' => $component->metrics,
                'observed_at' => now(),
            ]);

            $this->projectComponentNotificationService->notify($component, 'stale', 'danger');

            $count++;
        }

        return $count;
    }

    private function isOverdue(ProjectComponent $component): bool
    {
        return $component->last_heartbeat_at->lte(now()->subMinutes($component->interval_minutes));
    }
}
