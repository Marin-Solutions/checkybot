<?php

namespace App\Services;

use App\Models\ProjectComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ProjectComponentStaleService
{
    public function __construct(
        protected ProjectComponentNotificationService $projectComponentNotificationService
    ) {}

    public function markStaleComponents(): int
    {
        $count = 0;
        $checkedAt = now();

        $this->dueComponentsQuery($checkedAt)
            ->with('project')
            ->chunkById($this->chunkSize(), function ($components) use (&$count, $checkedAt): void {
                foreach ($components as $component) {
                    $component->forceFill([
                        'current_status' => 'danger',
                        'summary' => 'Heartbeat expired',
                        'is_stale' => true,
                        'stale_detected_at' => $checkedAt,
                    ])->save();

                    $component->heartbeats()->create([
                        'component_name' => $component->name,
                        'status' => 'danger',
                        'event' => 'stale',
                        'summary' => 'Heartbeat expired',
                        'metrics' => $component->metrics,
                        'observed_at' => $checkedAt,
                    ]);

                    $this->projectComponentNotificationService->notify($component, 'stale', 'danger');

                    $count++;
                }
            });

        return $count;
    }

    private function dueComponentsQuery(Carbon $checkedAt): Builder
    {
        return ProjectComponent::query()
            ->whereNotNull('interval_minutes')
            ->where('is_archived', false)
            ->where('is_stale', false)
            ->where(function (Builder $query) use ($checkedAt): void {
                $query
                    ->where(function (Builder $query) use ($checkedAt): void {
                        $query->whereNotNull('last_heartbeat_at');
                        $this->whereDueAt($query, 'last_heartbeat_at', $checkedAt);
                    })
                    ->orWhere(function (Builder $query) use ($checkedAt): void {
                        $query->whereNull('last_heartbeat_at');
                        $this->whereDueAt($query, 'created_at', $checkedAt);
                    });
            });
    }

    private function whereDueAt(Builder $query, string $column, Carbon $checkedAt): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $wrappedColumn = $grammar->wrap($column);
        $wrappedInterval = $grammar->wrap('interval_minutes');
        $checkedAtValue = $checkedAt->toDateTimeString();
        $graceMinutes = $this->staleGraceMinutes();

        match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $query->whereRaw(
                "{$wrappedColumn} <= DATE_SUB(?, INTERVAL ({$wrappedInterval} + ?) MINUTE)",
                [$checkedAtValue, $graceMinutes]
            ),
            'pgsql' => $query->whereRaw(
                "{$wrappedColumn} <= (CAST(? AS timestamp) - (({$wrappedInterval} + ?) * interval '1 minute'))",
                [$checkedAtValue, $graceMinutes]
            ),
            'sqlsrv' => $query->whereRaw(
                "{$wrappedColumn} <= DATEADD(minute, -({$wrappedInterval} + ?), ?)",
                // DATEADD receives the timestamp as its third argument, so this binding order differs from the other drivers.
                [$graceMinutes, $checkedAtValue]
            ),
            default => $query->whereRaw(
                "{$wrappedColumn} <= datetime(?, '-' || ({$wrappedInterval} + ?) || ' minutes')",
                [$checkedAtValue, $graceMinutes]
            ),
        };
    }

    public function staleThresholdAt(ProjectComponent $component): ?Carbon
    {
        if ($component->interval_minutes === null) {
            return null;
        }

        $anchorAt = $component->last_heartbeat_at ?? $component->created_at;

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
