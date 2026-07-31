<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectComponentHeartbeat;
use App\Support\ComponentStatusContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
        array $metrics,
        string $idempotencyKey
    ): array {
        $component = $project->components()
            ->where('name', $componentKey)
            ->where('source', 'package')
            ->where('is_archived', false)
            ->firstOrFail();
        $storedStatus = ComponentStatusContract::storedStatus($status);
        $observedAt = CarbonImmutable::parse($observedAt)->utc();

        return DB::transaction(function () use ($component, $componentKey, $storedStatus, $observedAt, $message, $metrics, $idempotencyKey): array {
            $component = $component->newQuery()
                ->lockForUpdate()
                ->findOrFail($component->id);

            $existingObservation = $component->heartbeats()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingObservation !== null) {
                if (! $this->matchesRequest($existingObservation, $storedStatus, $observedAt, $message, $metrics)) {
                    throw new ConflictHttpException('The Idempotency-Key was already used for a different component status request.');
                }

                return $this->observationResult($componentKey, $existingObservation);
            }

            $latestObservation = $component->heartbeats()
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first();

            $observation = $component->heartbeats()->create([
                'component_name' => $componentKey,
                'status' => $storedStatus,
                'event' => 'status',
                'summary' => $message,
                'metrics' => $metrics,
                'observed_at' => $observedAt,
                'idempotency_key' => $idempotencyKey,
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

            return $this->observationResult($componentKey, $observation);
        });
    }

    /**
     * @param  array<string, int|float|bool>  $metrics
     */
    private function matchesRequest(
        ProjectComponentHeartbeat $observation,
        string $storedStatus,
        CarbonImmutable $observedAt,
        string $message,
        array $metrics
    ): bool {
        return $observation->status === $storedStatus
            && $observation->summary === $message
            && $observation->observed_at?->equalTo($observedAt) === true
            && $observation->metrics === $metrics;
    }

    /**
     * @return array{component_key: string, status: string, observed_at: string, message: string, metrics: array<string, int|float|bool>}
     */
    private function observationResult(string $componentKey, ProjectComponentHeartbeat $observation): array
    {
        /** @var array<string, int|float|bool> $metrics */
        $metrics = is_array($observation->metrics) ? $observation->metrics : [];

        return [
            'component_key' => $componentKey,
            'status' => $observation->status,
            'observed_at' => $observation->observed_at->toIso8601String(),
            'message' => $observation->summary,
            'metrics' => $metrics,
        ];
    }
}
