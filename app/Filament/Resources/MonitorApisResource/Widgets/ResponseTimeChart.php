<?php

namespace App\Filament\Resources\MonitorApisResource\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ResponseTimeChart extends ChartWidget
{
    protected static ?string $heading = 'Response Times';
    protected static ?string $maxHeight = '300px';
    protected static ?string $pollingInterval = '10s';

    public ?MonitorApis $record = null;

    protected function getData(): array
    {
        if (!$this->record) {
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

        $results = MonitorApiResult::where('monitor_api_id', $this->record->id)
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('created_at')
            ->get();

        $data = $results->map(function ($result) {
            return [
                'value' => $result->response_time_ms,
                'time' => $result->created_at->format('H:i'),
            ];
        });

        return [
            'datasets' => [
                [
                    'label' => 'Response Time (ms)',
                    'data' => $data->pluck('value')->toArray(),
                    'borderColor' => '#10B981',
                    'fill' => false,
                ],
            ],
            'labels' => $data->pluck('time')->toArray(),
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
}
