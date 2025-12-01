<?php

namespace App\Filament\Widgets;

use App\Models\SeoCheck;
use Filament\Widgets\ChartWidget;

class SeoHealthScoreTrendWidget extends ChartWidget
{
    protected ?string $heading = 'SEO Health Score Trend';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public ?string $filter = '30days';

    public ?int $recordId = null;

    public ?int $websiteId = null;

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
            ->whereIn('status', ['completed', 'failed'])
            ->where('finished_at', '>=', now()->subDays($days))
            ->orderBy('finished_at')
            ->get(['finished_at', 'computed_health_score', 'status']);

        // If no data found, try without date filter to see if there's any data at all
        if ($data->isEmpty()) {
            $data = SeoCheck::where('website_id', $websiteId)
                ->whereIn('status', ['completed', 'failed'])
                ->orderBy('finished_at')
                ->get(['finished_at', 'computed_health_score', 'status']);
        }

        $labels = $data->map(function ($check) {
            return $check->finished_at ? $check->finished_at->format('M j') : 'Unknown';
        })->toArray();

        $scores = $data->map(function ($check) {
            // For failed checks, show 0 score
            if ($check->status === 'failed') {
                return 0;
            }

            return round($check->computed_health_score ?? 0, 1);
        })->toArray();

        // If still no data, return empty dataset with a message
        if ($data->isEmpty()) {
            return [
                'datasets' => [
                    [
                        'label' => 'Health Score (%)',
                        'data' => [],
                        'borderColor' => '#10b981',
                        'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                        'fill' => true,
                        'tension' => 0.4,
                    ],
                ],
                'labels' => ['No data available'],
            ];
        }

        // Create a single dataset with all scores in chronological order
        $scores = [];
        $pointColors = [];
        $pointBorderColors = [];

        foreach ($data as $check) {
            if ($check->status === 'failed') {
                $scores[] = 0;
                $pointColors[] = '#ef4444'; // Red for failed
                $pointBorderColors[] = '#ef4444';
            } else {
                $scores[] = round($check->computed_health_score ?? 0, 1);
                $pointColors[] = '#10b981'; // Green for completed
                $pointBorderColors[] = '#10b981';
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Health Score (%)',
                    'data' => $scores,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => $pointColors,
                    'pointBorderColor' => $pointBorderColors,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
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
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
                'tooltip' => [
                    'display' => true,
                ],
            ],
            'elements' => [
                'point' => [
                    'radius' => 4,
                    'hoverRadius' => 6,
                ],
                'line' => [
                    'tension' => 0.4,
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
        // First try to use the explicitly passed website ID
        if ($this->websiteId) {
            return $this->websiteId;
        }

        // Try to get website ID from route parameter
        $route = request()->route();
        if ($route && $route->hasParameter('record')) {
            $record = $route->parameter('record');
            if (is_numeric($record)) {
                $seoCheck = SeoCheck::find($record);
                if ($seoCheck) {
                    return $seoCheck->website_id;
                }
            }
        }

        // Try to get from parent component
        if (method_exists($this, 'getRecord') && $this->getRecord()) {
            return $this->getRecord()->website_id;
        }

        // Try to get from URL path
        $urlPath = request()->path();
        if (preg_match('/\/seo-checks\/(\d+)/', $urlPath, $matches)) {
            $seoCheckId = $matches[1];
            $seoCheck = SeoCheck::find($seoCheckId);
            if ($seoCheck) {
                return $seoCheck->website_id;
            }
        }

        // Fallback: get the most recent completed SEO check's website ID
        $latestSeoCheck = SeoCheck::where('status', 'completed')
            ->orderBy('finished_at', 'desc')
            ->first();

        return $latestSeoCheck ? $latestSeoCheck->website_id : null;
    }
}
