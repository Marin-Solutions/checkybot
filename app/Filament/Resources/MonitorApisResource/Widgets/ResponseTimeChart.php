<?php

namespace App\Filament\Resources\MonitorApisResource\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\ChartWidget;

class ResponseTimeChart extends ChartWidget
{
    protected ?string $heading = 'Response Times';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = '10s';

    public string|int|array $columnSpan = 'full';

    public ?MonitorApis $record = null;

    public ?string $timeRange = '24h';

    public function getHeading(): \Illuminate\Contracts\Support\Htmlable
    {
        return new \Illuminate\Support\HtmlString('
            <div class="flex items-center justify-between w-full">
                <span>Response Times</span>
                <div class="flex gap-2">
                    <button 
                        wire:click="$set(\'timeRange\', \'1h\')"
                        class="px-3 py-1 text-xs rounded-md font-medium '.($this->timeRange === '1h' ? 'bg-primary-600 text-white' : 'bg-gray-600 text-white dark:bg-gray-500').' hover:opacity-80 transition-opacity"
                    >
                        Last Hour
                    </button>
                    <button 
                        wire:click="$set(\'timeRange\', \'6h\')"
                        class="px-3 py-1 text-xs rounded-md font-medium '.($this->timeRange === '6h' ? 'bg-primary-600 text-white' : 'bg-gray-600 text-white dark:bg-gray-500').' hover:opacity-80 transition-opacity"
                    >
                        Last 6 Hours
                    </button>
                    <button 
                        wire:click="$set(\'timeRange\', \'24h\')"
                        class="px-3 py-1 text-xs rounded-md font-medium '.($this->timeRange === '24h' ? 'bg-primary-600 text-white' : 'bg-gray-600 text-white dark:bg-gray-500').' hover:opacity-80 transition-opacity"
                    >
                        Last 24 Hours
                    </button>
                    <button 
                        wire:click="$set(\'timeRange\', \'7d\')"
                        class="px-3 py-1 text-xs rounded-md font-medium '.($this->timeRange === '7d' ? 'bg-primary-600 text-white' : 'bg-gray-600 text-white dark:bg-gray-500').' hover:opacity-80 transition-opacity"
                    >
                        Last 7 Days
                    </button>
                </div>
            </div>
        ');
    }

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

        $timeRange = $this->timeRange ?? '24h';
        $interval = $this->getSmartInterval($timeRange);

        // Get results for the time range
        $results = MonitorApiResult::where('monitor_api_id', $this->record->id)
            ->where('created_at', '>=', $this->getTimeRangeStart($timeRange))
            ->select('response_time_ms', 'created_at')
            ->orderBy('created_at')
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
                return $key;
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
            '1h' => '5m',    // Last hour: 5-minute intervals (12 points)
            '6h' => '15m',   // Last 6 hours: 15-minute intervals (24 points)
            '24h' => '1h',   // Last 24 hours: 1-hour intervals (24 points)
            '7d' => '8h',    // Last 7 days: 8-hour intervals (21 points)
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
            '5m' => $date->format('Y-m-d H:').(floor($date->minute / 5) * 5),
            '15m' => $date->format('Y-m-d H:').(floor($date->minute / 15) * 15),
            '1h' => $date->format('Y-m-d H:00'),
            '8h' => $date->format('Y-m-d').' '.sprintf('%02d', floor($date->hour / 8) * 8).':00',
            default => $date->format('Y-m-d H:00')
        };
    }

    private function formatTimeLabel(\Carbon\Carbon $date, string $interval): string
    {
        return match ($interval) {
            '5m' => $date->format('H:i'),
            '15m' => $date->format('H:i'),
            '1h' => $date->format('H:00'),
            '8h' => $date->format('M j H:00'),
            default => $date->format('H:i')
        };
    }
}
