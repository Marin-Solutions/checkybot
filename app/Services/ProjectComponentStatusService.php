<?php

namespace App\Services;

use App\Models\Project;
use App\Support\ComponentStatusContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProjectComponentStatusService
{
    /**
     * @param  array<string, int|float|bool>  $metrics
     * @return array{component_key: string, status: string, observed_at: string, message: string, metrics: array<string, int|float|bool>}
     */
    public function report(
        Project $project,
        string $componentKey,
        string $status,
        string $observedAt,
        string $message,
        array $metrics
    ): array {
        $component = $project->components()
            ->where('name', $componentKey)
            ->where('source', 'package')
            ->where('is_archived', false)
            ->firstOrFail();
        $storedStatus = ComponentStatusContract::storedStatus($status);
        $observedAt = CarbonImmutable::parse($observedAt)->utc();

        return DB::transaction(function () use ($component, $componentKey, $storedStatus, $observedAt, $message, $metrics): array {
            $latestObservation = $component->heartbeats()
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first();

            $component->heartbeats()->create([
                'component_name' => $componentKey,
                'status' => $storedStatus,
                'event' => 'status',
                'summary' => $message,
                'metrics' => $metrics,
                'observed_at' => $observedAt,
            ]);

            if ($latestObservation === null || $observedAt->greaterThanOrEqualTo($latestObservation->observed_at)) {
                $component->forceFill([
                    'current_status' => $storedStatus,
                    'last_reported_status' => $storedStatus,
                    'status_observed_at' => $observedAt,
                    'summary' => $message,
                    'metrics' => $metrics,
                ])->save();
            }

            return [
                'component_key' => $componentKey,
                'status' => $storedStatus,
                'observed_at' => $observedAt->toIso8601String(),
                'message' => $message,
                'metrics' => $metrics,
            ];
        });
    }
}
