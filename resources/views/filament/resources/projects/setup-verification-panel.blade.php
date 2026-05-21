@php
    $badgeClasses = match ($tone ?? 'warning') {
        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
        'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300',
        default => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
    };

    $stepClasses = static function (string $status): array {
        if ($status === 'stale') {
            return [
                'badge' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
                'card' => 'border-warning-200/70 bg-warning-50/60 dark:border-warning-500/30 dark:bg-warning-500/10',
                'label' => 'Stale',
            ];
        }

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

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $repairLabel ?? 'Setup runbook' }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Use these steps in the Laravel application that should report to this Checkybot application.</p>
            </div>
        </div>

        <div class="mt-4 space-y-3">
            @foreach ($repairActions ?? [] as $repairAction)
                <div
                    @if (isset($repairAction['command']))
                        x-data="{ copied: false, command: @js($repairAction['command']) }"
                    @endif
                    class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900"
                >
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <h4 class="text-sm font-medium text-gray-950 dark:text-white">{{ $repairAction['title'] }}</h4>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $repairAction['detail'] }}</p>
                        </div>

                        @if (isset($repairAction['command']))
                            <button
                                type="button"
                                aria-label="Copy command: {{ $repairAction['command'] }}"
                                class="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/15 dark:bg-gray-950 dark:text-gray-100 dark:hover:bg-white/5 dark:focus:ring-offset-gray-950"
                                x-on:click="
                                    navigator.clipboard.writeText(command).then(() => {
                                        copied = true;
                                        setTimeout(() => copied = false, 1600);
                                    });
                                "
                            >
                                <span x-show="! copied">Copy command</span>
                                <span x-cloak x-show="copied">Copied</span>
                            </button>
                        @endif
                    </div>

                    @if (isset($repairAction['command']))
                        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded-md bg-gray-950 p-3 text-sm text-white">{{ $repairAction['command'] }}</pre>
                    @endif
                </div>
            @endforeach
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
