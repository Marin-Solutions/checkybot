<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($summary as $status => $bucket)
                @php
                    $isActive = $activeStatus === $status;
                    $colors = [
                        'healthy' => 'text-success-500 border-success-500/40',
                        'warning' => 'text-warning-500 border-warning-500/40',
                        'critical' => 'text-danger-500 border-danger-500/40',
                    ][$status];
                @endphp

                <a
                    href="{{ \App\Filament\Pages\HealthOverview::getUrl(['status' => $status]) }}"
                    @class([
                        'block rounded-lg border bg-white p-4 transition hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                        $colors,
                        'ring-1 ring-current' => $isActive,
                        'border-gray-200 dark:border-gray-800' => ! $isActive,
                    ])
                >
                    <div class="text-sm font-medium">{{ $bucket['label'] }}</div>
                    <div class="mt-3 flex items-end justify-between gap-3">
                        <div class="text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($bucket['count']) }}</div>
                        <div class="text-sm">{{ $bucket['percent'] }}%</div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($statusOptions as $status => $label)
                <a
                    href="{{ \App\Filament\Pages\HealthOverview::getUrl(['status' => $status]) }}"
                    @class([
                        'rounded-md border px-3 py-2 text-sm font-medium',
                        'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $activeStatus === $status,
                        'border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-900' => $activeStatus !== $status,
                    ])
                >
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-950">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Status</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Type</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Name</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($items as $item)
                            @php
                                $badge = [
                                    'healthy' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
                                    'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
                                    'critical' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300',
                                ][$item['status']];
                            @endphp

                            <tr>
                                <td class="px-4 py-3">
                                    <span class="{{ $badge }} inline-flex rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                                        {{ ucfirst($item['status']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item['type'] }}</td>
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                                    @if ($item['url'])
                                        <a href="{{ $item['url'] }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $item['name'] }}
                                        </a>
                                    @else
                                        {{ $item['name'] }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item['detail'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No monitored checks in this bucket.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
