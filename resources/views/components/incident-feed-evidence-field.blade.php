@props([
    'label',
    'value',
])

<div {{ $attributes->class('rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900') }}>
    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
    <div class="mt-1 break-words text-sm font-medium text-gray-950 dark:text-white">{{ filled($value) ? $value : '-' }}</div>
</div>
