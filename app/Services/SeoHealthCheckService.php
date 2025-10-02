<?php

namespace App\Services;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Support\Facades\Log;

class SeoHealthCheckService
{
    public function startManualCheck(Website $website): SeoCheck
    {
        Log::info("Starting manual SEO health check for website: {$website->url}");

        // Create new SEO check record
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
            'total_urls_crawled' => 0,
            'errors_found' => 0,
            'warnings_found' => 0,
            'notices_found' => 0,
        ]);

        // Dispatch job to start crawling
        SeoHealthCheckJob::dispatch($seoCheck);

        return $seoCheck;
    }

    public function getLatestHealthScore(Website $website): ?int
    {
        $latestCheck = $website->latestSeoCheck;

        return $latestCheck?->health_score;
    }

    public function getHealthScoreTrend(Website $website, int $days = 30): array
    {
        $checks = $website->seoChecks()
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->get(['health_score', 'created_at']);

        return $checks->map(function ($check) {
            return [
                'date' => $check->created_at->format('Y-m-d'),
                'score' => $check->health_score,
            ];
        })->toArray();
    }

    public function getIssueSummary(SeoCheck $seoCheck): array
    {
        $results = $seoCheck->crawlResults()->get();

        $summary = [
            'total_urls' => $results->count(),
            'errors' => 0,
            'warnings' => 0,
            'notices' => 0,
            'categories' => [
                'crawlability' => 0,
                'indexability' => 0,
                'onpage' => 0,
                'technical' => 0,
            ],
        ];

        foreach ($results as $result) {
            if ($result->issues) {
                foreach ($result->issues as $issue) {
                    switch ($issue['type']) {
                        case 'error':
                            $summary['errors']++;
                            break;
                        case 'warning':
                            $summary['warnings']++;
                            break;
                        case 'notice':
                            $summary['notices']++;
                            break;
                    }

                    if (isset($issue['category'])) {
                        $category = $issue['category'];
                        if (isset($summary['categories'][$category])) {
                            $summary['categories'][$category]++;
                        }
                    }
                }
            }
        }

        return $summary;
    }

    public function getTopIssues(SeoCheck $seoCheck, int $limit = 10): array
    {
        $results = $seoCheck->crawlResults()
            ->whereNotNull('issues')
            ->get();

        $issueCounts = [];

        foreach ($results as $result) {
            if ($result->issues) {
                foreach ($result->issues as $issue) {
                    $key = $issue['message'] ?? 'Unknown issue';

                    if (! isset($issueCounts[$key])) {
                        $issueCounts[$key] = [
                            'message' => $key,
                            'type' => $issue['type'] ?? 'unknown',
                            'category' => $issue['category'] ?? 'unknown',
                            'count' => 0,
                        ];
                    }

                    $issueCounts[$key]['count']++;
                }
            }
        }

        // Sort by count descending
        uasort($issueCounts, fn($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($issueCounts, 0, $limit);
    }
}
