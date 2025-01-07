<div style="display: flex; align-items: center;">
    <div style="flex-grow: 1; margin-right: 8px;">
        <div style="background-color: #e5e7eb; border-radius: 4px; overflow: hidden;">
            <div style="width: {{ $getState()['value'] }}%; background-color: {{ $getState()['value'] >= 80 ? '#ef4444' : ($getState()['value'] >= 70 ? '#f97316' : '#22c55e') }}; height: 20px;"></div>
        </div>
    </div>
    <span style="font-size: 12px; font-weight: 600;">{{ round($getState()['value']) }}%</span>
</div> 