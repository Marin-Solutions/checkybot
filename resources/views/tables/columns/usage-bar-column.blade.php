<div style="display: flex; align-items: center;">
    @php
        $state = $getState();
        $value = is_array($state) ? ($state['value'] ?? 0) : 0;
        $color = $value >= 80 ? '#ef4444' : ($value >= 70 ? '#f97316' : '#22c55e');
    @endphp
    
    <div style="flex-grow: 1; margin-right: 8px;">
        <div style="background-color: #e5e7eb; border-radius: 4px; overflow: hidden;">
            <div style="width: {{ $value }}%; background-color: {{ $color }}; height: 20px; transition: width 0.3s ease;"></div>
        </div>
    </div>
    <span style="font-size: 12px; font-weight: 600; min-width: 45px; text-align: right;">
        {{ round($value) }}%
    </span>
    
    {{-- Debug info --}}
    @if(app()->environment('local'))
        <div style="display: none;">
            {{ print_r($state, true) }}
        </div>
    @endif
</div> 