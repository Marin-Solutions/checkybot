<div class="flex items-center gap-3 w-full">
    @php
    $state = $getState();
    $value = $state['value'] ?? 0;
    $tooltip = $state['tooltip'] ?? '';
    $hasData = ($tooltip !== 'No data available');
    $color = match(true) {
    !$hasData => 'bg-gray-400 dark:bg-gray-500',
    $value >= 80 => 'bg-red-500 dark:bg-red-400',
    $value >= 70 => 'bg-yellow-500 dark:bg-yellow-400',
    default => 'bg-green-500 dark:bg-green-400'
    };
    $label = $state['label'] ?? '';
    @endphp

    @if($label)
    <span class="text-sm font-medium text-gray-700 dark:text-gray-300 min-w-[3rem]">{{ $label }}</span>
    @endif

    <div class="flex-1 max-w-[200px]">
        @if($hasData)
        <div
            class="bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden relative"
            style="height: 12px;"
            title="{{ $state['tooltip'] ?? number_format($value, 1) . '%' }}">
            <div
                class="{{ $color }} transition-all duration-300 h-full rounded-full"
                style="width: {{ max(0, min(100, $value)) }}%;"></div>
        </div>
        @else
        <div
            class="bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden relative flex items-center justify-center"
            style="height: 12px;"
            title="{{ $state['tooltip'] ?? 'No data available' }}">
            <span class="text-[8px] text-gray-500 dark:text-gray-400 font-medium">No data</span>
        </div>
        @endif
    </div>

    <span class="text-sm font-medium text-gray-600 dark:text-gray-400 min-w-[3.5rem] text-right">
        @if($hasData)
        {{ number_format($value, 1) }}%
        @else
        <span class="text-gray-400 dark:text-gray-500">—</span>
        @endif
    </span>
</div>