<div class="flex items-center w-full">
    @php
        $state = $getState();
        $value = is_array($state) ? ($state['value'] ?? 0) : 0;
        $color = $value >= 80 ? 'bg-red-500' : ($value >= 70 ? 'bg-orange-500' : 'bg-green-500');
    @endphp
    
    <div class="flex-1 mr-2">
        <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
            <div class="{{ $color }} h-2.5 rounded-full" style="width: {{ $value }}%"></div>
        </div>
    </div>
    <div class="text-sm font-semibold min-w-[3rem] text-right">
        {{ round($value) }}%
    </div>

    @if(app()->environment('local'))
        <div class="hidden">
            {{ print_r($state, true) }}
        </div>
    @endif
</div> 