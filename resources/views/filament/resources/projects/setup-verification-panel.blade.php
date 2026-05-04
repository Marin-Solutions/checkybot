@php
    $badgeClasses = match ($tone ?? 'warning') {
        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
        'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300',
        default => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
    };

    $stepClasses = static function (string $status): array {
        if ($status === 'complete') {
            return [
                'badge' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
                'card' => 'border-success-200/70 bg-success-50/60 dark:border-success-500/30 dark:bg-success-500/10',
                'label' => 'Complete',
            ];
        }

        return [
            'badge' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
            'card' => 'border-warning-200/70 bg-warning-50/60 dark:border-warning-500/30 dark:bg-warning-500/10',
            'label' => 'Pending',
        ];
    };
@endphp

<div class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Current setup state</h3>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeClasses }}">
                    {{ $label }}
                </span>
            </div>

            <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-300">{{ $summary }}</p>
        </div>

        <div class="max-w-md rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-600 dark:border-white/15 dark:text-gray-300">
            <span class="font-medium text-gray-950 dark:text-white">Next action:</span>
            {{ $action }}
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2">
        @foreach ($steps as $step)
            @php($styles = $stepClasses($step['status']))

            <div class="rounded-xl border p-4 {{ $styles['card'] }}">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $step['title'] }}</h4>
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $styles['badge'] }}">
                        {{ $styles['label'] }}
                    </span>
                </div>

                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $step['description'] }}</p>
            </div>
        @endforeach
    </div>
</div>
