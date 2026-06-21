<?php

namespace App\Services;

use App\Models\ProjectComponent;
use Illuminate\Support\Carbon;

class ProjectComponentStaleService
{
    public function __construct(
        protected ProjectComponentNotificationService $projectComponentNotificationService
    ) {}

    public function markStaleComponents(): int
    {
        return 0;
    }

    public function staleThresholdAt(ProjectComponent $component): ?Carbon
    {
        if ($component->interval_minutes === null) {
            return null;
        }

        $anchorAt = $component->created_at;

        if ($anchorAt === null) {
            return null;
        }

        return $anchorAt
            ->copy()
            ->addMinutes($component->interval_minutes + $this->staleGraceMinutes());
    }

    public function staleGraceMinutes(): int
    {
        return max(0, (int) config('monitor.project_component_stale_grace_minutes'));
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('monitor.project_component_stale_chunk_size', 500));
    }
}
