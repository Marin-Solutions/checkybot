@php
$state = $getState();

$value = 0;
$tooltip = '';

if (is_array($state)) {
$value = $state['value'] ?? 0;
$tooltip = $state['tooltip'] ?? '';
}

// Only consider it "No data" if the tooltip explicitly says so
$hasData = $tooltip !== 'No data available';

// Ensure value is within 0-100 range
$percentage = max(0, min(100, (float) $value));

$colorClass = match(true) {
!$hasData => 'bg-gray-200 dark:bg-gray-700',
$percentage >= 90 => 'bg-red-500',
$percentage >= 75 => 'bg-yellow-500',
default => 'bg-green-500',
};
@endphp

<div class="flex items-center w-full gap-3">
    <div class="flex-grow bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 overflow-hidden" title="{{ $tooltip }}">
        @if($hasData)
        <div class="{{ $colorClass }} h-full rounded-full transition-all duration-500" style="width: {{ $percentage }}%;"></div>
        @endif
    </div>
    <div class="text-xs font-medium text-gray-700 dark:text-gray-300 w-10 text-right">
        @if($hasData)
        {{ number_format($percentage, 1) }}%
        @else
        <span class="text-gray-400 dark:text-gray-500">-</span>
        @endif
    </div>
</div>