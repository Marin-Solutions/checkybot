<?php

namespace App\Services;

use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoReportGenerationService
{
    public function generateComprehensiveReport(SeoCheck $seoCheck, string $format = 'html'): string
    {
        $website = $seoCheck->website;
        $issues = $seoCheck->seoIssues()->with('seoCrawlResult')->get();
        $crawlResults = $seoCheck->crawlResults;

        // Generate filename
        $filename = $this->generateFilename($website, $seoCheck, $format);

        // Generate report content based on format
        switch ($format) {
            case 'csv':
                return $this->generateCsvReport($seoCheck, $issues, $crawlResults, $filename);
            case 'json':
                return $this->generateJsonReport($seoCheck, $issues, $crawlResults, $filename);
            case 'html':
            default:
                return $this->generateHtmlReport($seoCheck, $issues, $crawlResults, $filename);
        }
    }

    protected function generateFilename(Website $website, SeoCheck $seoCheck, string $format): string
    {
        $safeWebsiteName = Str::slug($website->name);
        $timestamp = $seoCheck->finished_at->format('Y-m-d_H-i-s');

        return "SEO_Report_{$safeWebsiteName}_{$timestamp}.{$format}";
    }

    protected function generateHtmlReport(SeoCheck $seoCheck, $issues, $crawlResults, string $filename): string
    {
        $website = $seoCheck->website;

        $html = view('exports.seo-check-pdf', [
            'seoCheck' => $seoCheck,
            'website' => $website,
            'issues' => $issues,
            'crawlResults' => $crawlResults,
        ])->render();

        // Save to storage
        Storage::put("reports/{$filename}", $html);

        return $filename;
    }

    protected function generateCsvReport(SeoCheck $seoCheck, $issues, $crawlResults, string $filename): string
    {
        $website = $seoCheck->website;

        $csvContent = $this->buildCsvContent($issues);

        // Save to storage
        Storage::put("reports/{$filename}", $csvContent);

        return $filename;
    }

    protected function generateJsonReport(SeoCheck $seoCheck, $issues, $crawlResults, string $filename): string
    {
        $website = $seoCheck->website;

        $reportData = [
            'report_metadata' => [
                'generated_at' => now()->toISOString(),
                'report_id' => $seoCheck->id,
                'website' => [
                    'name' => $website->name,
                    'url' => $website->url,
                ],
            ],
            'seo_check_summary' => [
                'status' => $seoCheck->status,
                'started_at' => $seoCheck->started_at?->toISOString(),
                'finished_at' => $seoCheck->finished_at?->toISOString(),
                'duration_seconds' => $seoCheck->getDurationInSeconds(),
                'total_urls_crawled' => $seoCheck->total_urls_crawled,
                'total_crawlable_urls' => $seoCheck->total_crawlable_urls,
                'health_score' => $seoCheck->computed_health_score,
                'sitemap_used' => $seoCheck->sitemap_used,
                'robots_txt_checked' => $seoCheck->robots_txt_checked,
            ],
            'issue_summary' => [
                'total_issues' => $issues->count(),
                'errors_count' => $seoCheck->computed_errors_count ?? 0,
                'warnings_count' => $seoCheck->computed_warnings_count ?? 0,
                'notices_count' => $seoCheck->computed_notices_count ?? 0,
                'issues_by_type' => $issues->groupBy('type')->map->count(),
                'issues_by_severity' => $issues->groupBy('severity')->map->count(),
            ],
            'issues' => $issues->map(function ($issue) {
                return [
                    'id' => $issue->id,
                    'type' => $issue->type,
                    'severity' => $issue->severity->value,
                    'url' => $issue->url,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'data' => $issue->data,
                    'status_code' => $issue->seoCrawlResult?->status_code,
                    'response_time_ms' => $issue->seoCrawlResult?->response_time,
                    'page_size_bytes' => $issue->seoCrawlResult?->page_size,
                    'found_at' => $issue->created_at->toISOString(),
                ];
            }),
            'crawl_results_summary' => [
                'total_pages' => $crawlResults->count(),
                'status_codes' => $crawlResults->groupBy('status_code')->map->count(),
                'average_response_time' => $crawlResults->avg('response_time'),
                'average_page_size' => $crawlResults->avg('page_size'),
                'pages_with_issues' => $issues->pluck('url')->unique()->count(),
            ],
        ];

        $jsonContent = json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Save to storage
        Storage::put("reports/{$filename}", $jsonContent);

        return $filename;
    }

    protected function buildCsvContent($issues): string
    {
        $output = fopen('php://temp', 'r+');

        // CSV Headers
        fputcsv($output, [
            'URL',
            'Issue Type',
            'Severity',
            'Title',
            'Description',
            'Status Code',
            'Page Title',
            'Meta Description',
            'H1 Tag',
            'Response Time (ms)',
            'Page Size (bytes)',
            'Found At',
        ]);

        // CSV Data
        foreach ($issues as $issue) {
            $crawlResult = $issue->seoCrawlResult;
            fputcsv($output, [
                $issue->url,
                $issue->type,
                $issue->severity->value,
                $issue->title,
                $issue->description,
                $crawlResult ? $crawlResult->status_code : 'N/A',
                $crawlResult ? $crawlResult->title : 'N/A',
                $crawlResult ? $crawlResult->meta_description : 'N/A',
                $crawlResult ? $crawlResult->h1_tag : 'N/A',
                $crawlResult ? $crawlResult->response_time : 'N/A',
                $crawlResult ? $crawlResult->page_size : 'N/A',
                $issue->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }

    public function generateHistoricalReport(Website $website, int $days = 30, string $format = 'json'): string
    {
        $checks = SeoCheck::where('website_id', $website->id)
            ->where('status', 'completed')
            ->where('finished_at', '>=', now()->subDays($days))
            ->orderBy('finished_at')
            ->get();

        $filename = 'SEO_Historical_Report_'.Str::slug($website->name).'_'.now()->format('Y-m-d').".{$format}";

        $reportData = [
            'report_metadata' => [
                'generated_at' => now()->toISOString(),
                'website' => [
                    'name' => $website->name,
                    'url' => $website->url,
                ],
                'period_days' => $days,
                'checks_analyzed' => $checks->count(),
            ],
            'trend_analysis' => [
                'health_score_trend' => $checks->pluck('computed_health_score')->filter()->values(),
                'issues_trend' => [
                    'errors' => $checks->pluck('computed_errors_count')->values(),
                    'warnings' => $checks->pluck('computed_warnings_count')->values(),
                    'notices' => $checks->pluck('computed_notices_count')->values(),
                ],
                'urls_crawled_trend' => $checks->pluck('total_urls_crawled')->values(),
            ],
            'summary_statistics' => [
                'average_health_score' => $checks->avg('computed_health_score'),
                'best_health_score' => $checks->max('computed_health_score'),
                'worst_health_score' => $checks->min('computed_health_score'),
                'total_checks' => $checks->count(),
                'average_issues_per_check' => [
                    'errors' => $checks->avg('computed_errors_count'),
                    'warnings' => $checks->avg('computed_warnings_count'),
                    'notices' => $checks->avg('computed_notices_count'),
                ],
            ],
            'check_history' => $checks->map(function ($check) {
                return [
                    'id' => $check->id,
                    'finished_at' => $check->finished_at->toISOString(),
                    'health_score' => $check->computed_health_score,
                    'urls_crawled' => $check->total_urls_crawled,
                    'issues' => [
                        'errors' => $check->computed_errors_count ?? 0,
                        'warnings' => $check->computed_warnings_count ?? 0,
                        'notices' => $check->computed_notices_count ?? 0,
                    ],
                ];
            }),
        ];

        $content = json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Save to storage
        Storage::put("reports/{$filename}", $content);

        return $filename;
    }

    public function getReportDownloadUrl(string $filename): string
    {
        return route('seo.report.download', ['filename' => $filename]);
    }

    public function cleanupOldReports(int $daysOld = 30): int
    {
        $cutoffDate = now()->subDays($daysOld);
        $deletedCount = 0;

        $files = Storage::files('reports');
        foreach ($files as $file) {
            $lastModified = Storage::lastModified($file);
            if ($lastModified < $cutoffDate->timestamp) {
                Storage::delete($file);
                $deletedCount++;
            }
        }

        return $deletedCount;
    }
}
