<?php

namespace App\Filament\Resources\MonitorApisResource\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\ChartWidget;

class ResponseTimeChart extends ChartWidget
{
    protected static ?string $heading = 'Response Times';

    public function getHeading(): \Illuminate\Contracts\Support\Htmlable
    {
        return new \Illuminate\Support\HtmlString('
            <div class="flex items-center justify-between w-full">
                <span>Response Times</span>
                <div class="flex gap-2">
                    <button 
                        wire:click="$set(\'timeRange\', \'1h\')"
                        class="px-3 py-1 text-xs rounded-md ' . ($this->timeRange === '1h' ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300') . ' hover:opacity-80"
                    >
                        1 Hour
                    </button>
                    <button 
                        wire:click="$set(\'timeRange\', \'6h\')"
                        class="px-3 py-1 text-xs rounded-md ' . ($this->timeRange === '6h' ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300') . ' hover:opacity-80"
                    >
                        6 Hours
                    </button>
                    <button 
                        wire:click="$set(\'timeRange\', \'24h\')"
                        class="px-3 py-1 text-xs rounded-md ' . ($this->timeRange === '24h' ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300') . ' hover:opacity-80"
                    >
                        24 Hours
                    </button>
                    <button 
                        wire:click="$set(\'timeRange\', \'7d\')"
                        class="px-3 py-1 text-xs rounded-md ' . ($this->timeRange === '7d' ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300') . ' hover:opacity-80"
                    >
                        7 Days
                    </button>
                </div>
            </div>
        ');
    }

    protected static ?string $maxHeight = '300px';

    protected static ?string $pollingInterval = '30s';

    public string|int|array $columnSpan = 'full';

    public ?string $timeRange = '24h';

    public ?MonitorApis $record = null;

    protected function getData(): array
    {
        if (! $this->record) {
            return [
                'datasets' => [
                    [
                        'label' => 'Response Time (ms)',
                        'data' => [],
                        'borderColor' => '#10B981',
                        'fill' => false,
                    ],
                ],
                'labels' => [],
            ];
        }

        // Smart interval based on time range
        $timeRange = $this->timeRange ?? '24h';
        $interval = $this->getSmartInterval($timeRange);

        // Get results for the time range with smart intervals (optimized query)
        $results = MonitorApiResult::where('monitor_api_id', $this->record->id)
            ->where('created_at', '>=', $this->getTimeRangeStart($timeRange))
            ->select('response_time_ms', 'created_at') // Only select needed columns
            ->orderBy('created_at')
            ->limit(1000) // Limit to prevent memory issues
            ->get();

        // Apply smart grouping based on time range
        $groupedResults = $results->groupBy(function ($result) use ($interval) {
            return $this->groupByInterval($result->created_at, $interval);
        })
            ->map(function ($group) use ($interval) {
                return [
                    'value' => round($group->avg('response_time_ms')),
                    'time' => $this->formatTimeLabel($group->first()->created_at, $interval),
                    'count' => $group->count(),
                ];
            })
            ->sortBy(function ($item, $key) {
                return $key; // Sort by time
            });

        return [
            'datasets' => [
                [
                    'label' => 'Response Time (ms)',
                    'data' => $groupedResults->pluck('value')->toArray(),
                    'borderColor' => '#10B981',
                    'fill' => false,
                ],
            ],
            'labels' => $groupedResults->pluck('time')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Response Time (ms)',
                    ],
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Time',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'enabled' => true,
                ],
            ],
        ];
    }

    private function getSmartInterval(string $timeRange): string
    {
        return match ($timeRange) {
            '1h' => '5m',    // Last hour: 5-minute intervals
            '6h' => '30m',  // Last 6 hours: 30-minute intervals
            '24h' => '1h',  // Last 24 hours: 1-hour intervals
            '7d' => '6h',   // Last 7 days: 6-hour intervals
            default => '1h'
        };
    }

    private function getTimeRangeStart(string $timeRange): \Carbon\Carbon
    {
        return match ($timeRange) {
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => now()->subDay()
        };
    }

    private function groupByInterval(\Carbon\Carbon $date, string $interval): string
    {
        return match ($interval) {
            '5m' => $date->format('Y-m-d H:') . (floor($date->minute / 5) * 5),
            '30m' => $date->format('Y-m-d H:') . (floor($date->minute / 30) * 30),
            '1h' => $date->format('Y-m-d H:00'),
            '6h' => $date->format('Y-m-d') . ' ' . (floor($date->hour / 6) * 6) . ':00',
            default => $date->format('Y-m-d H:00')
        };
    }

    private function formatTimeLabel(\Carbon\Carbon $date, string $interval): string
    {
        return match ($interval) {
            '5m' => $date->format('H:i'),
            '30m' => $date->format('H:i'),
            '1h' => $date->format('H:00'),
            '6h' => $date->format('M j H:00'),
            default => $date->format('H:i')
        };
    }
}
