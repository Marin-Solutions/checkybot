@php
    $record = $getRecord();
    $hasFreshMetrics = $record instanceof \App\Models\Server && $record->hasFreshLatestHistory();
    $hasMetricHistory = $record instanceof \App\Models\Server && filled($record->latest_server_history_created_at);
    $label = $hasMetricHistory ? __('Stale') : __('Unknown');
    $tooltip = $getTooltip() ?: ($hasMetricHistory
        ? __('Metrics are stale because the reporter has not checked in recently.')
        : __('Metric freshness is unknown because no reporter data has been received.'));
    $poll = $getPoll();
@endphp

@if (! $hasFreshMetrics)
    @php
        $barStyles = \Filament\Support\get_color_css_variables('gray', shades: [600]);
    @endphp

    <div
        class="fi-ta-progress-col"
        title="{{ $tooltip }}"
        data-stale-server-metric
        @if ($poll)
            wire:poll.{{ $poll }}
        @endif
    >
        <div class="fi-ta-progress-track">
            <div style="{{ $barStyles }}; width: 100%; opacity: 0.25" class="fi-ta-progress-indicator"></div>
        </div>

        <span class="fi-ta-progress-label text-gray-500 dark:text-gray-400">{{ $label }}</span>
    </div>
@else
    @php
        $color = $getColor();
        $barStyles = \Filament\Support\get_color_css_variables(
            $color,
            shades: [600],
        );
        $progress = $getProgress();
        $displayProgress = (int) floor(max(0, min(100, (float) $progress)));
    @endphp

    <div
        class="fi-ta-progress-col"
        title="{{ $tooltip }}"
        @if ($poll)
            wire:poll.{{ $poll }}
        @endif
    >
        <div class="fi-ta-progress-track">
            <div style="{{ $barStyles }}; width: {{ min($progress, 100) }}%" class="fi-ta-progress-indicator"></div>
        </div>

        <span class="fi-ta-progress-label">{{ $displayProgress }}%</span>
    </div>
@endif
