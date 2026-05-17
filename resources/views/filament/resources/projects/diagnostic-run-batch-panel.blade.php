@php
    $status = $batch['status'] ?? 'unavailable';
    $totalJobs = (int) ($batch['total_jobs'] ?? 0);
    $pendingJobs = (int) ($batch['pending_jobs'] ?? 0);
    $failedJobs = (int) ($batch['failed_jobs'] ?? 0);
    $completedJobs = max(0, $totalJobs - $pendingJobs);
    $progress = $totalJobs > 0 ? (int) round(($completedJobs / $totalJobs) * 100) : 0;

    $badgeClasses = match ($status) {
        'finished' => $failedJobs > 0
            ? 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300'
            : 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
        'running' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300',
        'cancelled', 'unavailable' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300',
        default => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
    };

    $label = match ($status) {
        'finished' => $failedJobs > 0 ? 'Finished with failures' : 'Finished',
        'running' => 'Running',
        'cancelled' => 'Cancelled',
        'unavailable' => 'Unavailable',
        default => 'Pending',
    };
@endphp

<div
    @if (in_array($status, ['pending', 'running'], true))
        wire:poll.10s
    @endif
    class="space-y-4"
>
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Latest diagnostic batch</h3>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeClasses }}">
                    {{ $label }}
                </span>
            </div>

            <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                @if ($batch === null)
                    Checkybot no longer has batch details for this run. The batch may have expired from queue storage.
                @else
                    {{ $completedJobs }} of {{ $totalJobs }} queued {{ str('check')->plural($totalJobs) }} have finished.
                    @if ($failedJobs > 0)
                        {{ $failedJobs }} {{ str('check')->plural($failedJobs) }} failed during execution.
                    @endif
                @endif
            </p>
        </div>

        <div class="max-w-md text-xs text-gray-600 dark:text-gray-300">
            <div class="font-medium text-gray-950 dark:text-white">Batch ID</div>
            <div class="mt-1 break-all font-mono">{{ $batch['id'] ?? $batchId }}</div>
        </div>
    </div>

    @if ($batch !== null)
        <div class="space-y-2">
            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div class="h-full rounded-full bg-primary-600 dark:bg-primary-500" style="width: {{ $progress }}%"></div>
            </div>

            <div class="grid gap-3 text-sm md:grid-cols-4">
                <div class="border-l border-gray-200 pl-3 dark:border-white/10">
                    <div class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Total</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $totalJobs }}</div>
                </div>
                <div class="border-l border-gray-200 pl-3 dark:border-white/10">
                    <div class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Pending</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $pendingJobs }}</div>
                </div>
                <div class="border-l border-gray-200 pl-3 dark:border-white/10">
                    <div class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Failed</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $failedJobs }}</div>
                </div>
                <div class="border-l border-gray-200 pl-3 dark:border-white/10">
                    <div class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Queued</div>
                    <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $queuedAt?->toDayDateTimeString() ?? 'Unknown' }}
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
