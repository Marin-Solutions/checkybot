<div class="flex items-center w-full px-4">
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
        <div 
            class="bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden" 
            style="height: 8px;"
            title="{{ number_format($value, 1) }}% {{ $state['tooltip'] ?? '' }}"
        >
            <div 
                class="{{ $color }} transition-all shadow-sm" 
                style="width: {{ $value }}%; height: 8px;"
            ></div>
        </div>
    </div>
</div> 