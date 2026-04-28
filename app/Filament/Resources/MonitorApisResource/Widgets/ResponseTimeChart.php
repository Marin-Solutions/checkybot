<?php

namespace App\Filament\Resources\MonitorApisResource\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

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
        $intervalSeconds = $this->getIntervalSeconds($interval);
        $bucketExpression = $this->getBucketExpression($intervalSeconds);

        $results = MonitorApiResult::query()
            ->where('monitor_api_id', $this->record->id)
            ->where('is_on_demand', false)
            ->where('created_at', '>=', $this->getTimeRangeStart($timeRange))
            ->selectRaw("{$bucketExpression} as time_bucket")
            ->selectRaw('AVG(response_time_ms) as avg_response_time')
            ->selectRaw('MIN(created_at) as first_seen_at')
            ->groupByRaw($bucketExpression)
            ->orderByRaw($bucketExpression)
            ->get();

        $values = $results
            ->map(fn ($result) => (int) round((float) $result->avg_response_time))
            ->toArray();

        $labels = $results
            ->map(fn ($result) => $this->formatTimeLabel(Carbon::parse($result->first_seen_at), $interval))
            ->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Response Time (ms)',
                    'data' => $values,
                    'borderColor' => '#10B981',
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
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

    private function getIntervalSeconds(string $interval): int
    {
        return match ($interval) {
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '8h' => 28800,
            default => 3600
        };
    }

    private function getBucketExpression(int $intervalSeconds): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST(strftime('%s', created_at) / {$intervalSeconds} AS INTEGER)";
        }

        return "FLOOR(UNIX_TIMESTAMP(created_at) / {$intervalSeconds})";
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
