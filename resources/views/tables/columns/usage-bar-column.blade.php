<div class="flex items-center w-full gap-2">
    @php
        $state = $getState();
        $value = $state['value'] ?? 0;
        $color = match(true) {
            $value >= 80 => 'bg-red-500 dark:bg-red-400',
            $value >= 70 => 'bg-yellow-500 dark:bg-yellow-400',
            default => 'bg-green-500 dark:bg-green-400'
        };
    @endphp

    <div class="flex-1">
        <div class="bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden" style="height: 8px;">
            <div 
                class="{{ $color }} transition-all shadow-sm" 
                style="width: {{ $value }}%; height: 8px;"
                title="{{ $state['tooltip'] ?? '' }}"
            ></div>
        </div>
    </div>

    <div class="min-w-[3rem] text-sm text-end tabular-nums">
        {{ number_format($value, 1) }}%
    </div>
</div> 