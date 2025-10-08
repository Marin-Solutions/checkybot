<?php

namespace App\Filament\Widgets;

use App\Models\SeoCheck;
use Filament\Widgets\ChartWidget;

class SeoHealthScoreTrendWidget extends ChartWidget
{
    protected static ?string $heading = 'SEO Health Score Trend';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30days';

    protected function getFilters(): ?array
    {
        return [
            '7days' => 'Last 7 days',
            '30days' => 'Last 30 days',
            '90days' => 'Last 90 days',
            '1year' => 'Last year',
        ];
    }

    protected function getData(): array
    {
        $websiteId = $this->getWebsiteId();

        if (! $websiteId) {
            return [
                'datasets' => [
                    [
                        'label' => 'Health Score',
                        'data' => [],
                        'borderColor' => '#3b82f6',
                        'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                        'fill' => true,
                    ],
                ],
                'labels' => [],
            ];
        }

        $days = match ($this->filter) {
            '7days' => 7,
            '30days' => 30,
            '90days' => 90,
            '1year' => 365,
            default => 30,
        };

        $data = SeoCheck::where('website_id', $websiteId)
            ->where('status', 'completed')
            ->where('finished_at', '>=', now()->subDays($days))
            ->orderBy('finished_at')
            ->get(['finished_at', 'computed_health_score']);

        $labels = $data->map(function ($check) {
            return $check->finished_at->format('M j');
        })->toArray();

        $scores = $data->map(function ($check) {
            return round($check->computed_health_score ?? 0, 1);
        })->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Health Score (%)',
                    'data' => $scores,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'ticks' => [
                        'callback' => 'function(value) { return value + "%"; }',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => 'function(context) { return "Health Score: " + context.parsed.y + "%"; }',
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getWebsiteId(): ?int
    {
        // Try to get website ID from route parameter
        $route = request()->route();
        if ($route && $route->hasParameter('record')) {
            $record = $route->parameter('record');
            if (is_numeric($record)) {
                $seoCheck = SeoCheck::find($record);

                return $seoCheck ? $seoCheck->website_id : null;
            }
        }

        // Try to get from parent component
        if (method_exists($this, 'getRecord') && $this->getRecord()) {
            return $this->getRecord()->website_id;
        }

        return null;
    }
}
