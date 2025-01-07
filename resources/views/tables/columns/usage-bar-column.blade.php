<div class="flex items-center w-full gap-2">
    @php
        $state = $getState();
        $value = $state['value'] ?? 0;
        $color = match(true) {
            $value >= 80 => 'bg-danger-500',
            $value >= 70 => 'bg-warning-500',
            default => 'bg-success-500'
        };
    @endphp

    <div class="flex-1">
        <div class="bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden" style="height: 8px;">
            <div 
                class="{{ $color }} transition-all" 
                style="width: {{ $value }}%; height: 8px;"
                title="{{ $state['tooltip'] ?? '' }}"
            ></div>
        </div>
    </div>

    <div class="min-w-[3rem] text-sm text-end tabular-nums">
        {{ number_format($value, 1) }}%
    </div>
</div> 