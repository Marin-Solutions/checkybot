<?php

namespace App\Filament\Widgets;

use App\Models\SeoCheck;
use App\Models\Website;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class SeoHealthScoreTrendWidget extends ChartWidget
{
    /**
     * @var array<string>
     */
    public array $discoveredSchemaNames = [];

    public bool $areSchemaStateUpdateHooksDisabledForTesting = false;

    protected ?string $heading = 'SEO Health Score Trend';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public ?string $filter = '30days';

    public ?int $recordId = null;

    public ?int $websiteId = null;

    public static function canView(): bool
    {
        $route = request()->route();

        if (! $route || ! request()->routeIs('filament.admin.resources.seo-checks.view')) {
            return false;
        }

        $record = $route->parameter('record');
        $seoCheck = $record instanceof SeoCheck
            ? $record
            : (is_numeric($record) ? SeoCheck::find($record) : null);

        return $seoCheck !== null
            && (Auth::user()?->can('view', $seoCheck) ?? false);
    }

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
            ->whereHas('website', fn ($query) => $query->where('created_by', Auth::id()))
            ->whereIn('status', ['completed', 'failed'])
            ->where('finished_at', '>=', now()->subDays($days))
            ->orderBy('finished_at')
            ->get(['finished_at', 'computed_health_score', 'status']);

        // If no data found, try without date filter to see if there's any data at all
        if ($data->isEmpty()) {
            $data = SeoCheck::where('website_id', $websiteId)
                ->whereHas('website', fn ($query) => $query->where('created_by', Auth::id()))
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
            return $this->authorizedWebsiteId($this->websiteId);
        }

        if ($this->recordId) {
            return $this->websiteIdFromSeoCheck($this->recordId);
        }

        // Try to get website ID from route parameter
        $route = request()->route();
        if ($route && $route->hasParameter('record')) {
            $record = $route->parameter('record');

            if ($record instanceof SeoCheck) {
                return $this->authorizedWebsiteId($record->website_id);
            }

            if (is_numeric($record)) {
                return $this->websiteIdFromSeoCheck((int) $record);
            }
        }

        // Try to get from parent component
        if (method_exists($this, 'getRecord') && $this->getRecord()) {
            return $this->authorizedWebsiteId($this->getRecord()->website_id);
        }

        // Try to get from URL path
        $urlPath = request()->path();
        if (preg_match('/\/seo-checks\/(\d+)/', $urlPath, $matches)) {
            return $this->websiteIdFromSeoCheck((int) $matches[1]);
        }

        return null;
    }

    protected function websiteIdFromSeoCheck(int $seoCheckId): ?int
    {
        $seoCheck = SeoCheck::find($seoCheckId);

        return $seoCheck
            ? $this->authorizedWebsiteId($seoCheck->website_id)
            : null;
    }

    protected function authorizedWebsiteId(int $websiteId): ?int
    {
        $authUserId = Auth::id();

        if (! $authUserId) {
            return null;
        }

        $isAuthorized = Website::query()
            ->whereKey($websiteId)
            ->where('created_by', $authUserId)
            ->exists();

        return $isAuthorized ? $websiteId : null;
    }
}
