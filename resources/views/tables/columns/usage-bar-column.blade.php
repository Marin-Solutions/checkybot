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
        <div class="bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden h-2">
            <div 
                class="{{ $color }} h-2 transition-all" 
                style="width: {{ $value }}%"
                title="{{ $state['tooltip'] ?? '' }}"
            ></div>
        </div>
    </div>

    <div class="min-w-[3rem] text-sm text-end tabular-nums">
        {{ number_format($value, 1) }}%
    </div>
</div> 