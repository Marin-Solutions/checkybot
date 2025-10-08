<?php

namespace App\Services;

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SeoIssueDetectionService
{
    protected Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 10,
            'allow_redirects' => false,
            'verify' => false,
        ]);
    }

    public function detectIssues(SeoCheck $seoCheck): void
    {
        $crawlResults = $seoCheck->crawlResults;
        $allIssues = collect();

        Log::info("SEO Issue Detection: Starting for SEO Check {$seoCheck->id} with {$crawlResults->count()} crawl results");

        $pageIssueCount = 0;
        foreach ($crawlResults as $result) {
            $issues = $this->detectIssuesForPage($result, $crawlResults);
            $pageIssueCount += $issues->count();
            $allIssues = $allIssues->merge($issues);
        }

        Log::info("SEO Issue Detection: Found {$pageIssueCount} page-level issues");

        // Detect cross-page issues
        $crossPageIssues = $this->detectCrossPageIssues($crawlResults);
        $allIssues = $allIssues->merge($crossPageIssues);

        Log::info("SEO Issue Detection: Found {$crossPageIssues->count()} cross-page issues");
        Log::info("SEO Issue Detection: Total issues to insert: {$allIssues->count()}");

        // Bulk insert issues in batches to avoid MySQL packet size limits
        if ($allIssues->isNotEmpty()) {
            $allIssues->chunk(100)->each(function ($chunk) {
                $chunkArray = $chunk->toArray();
                // JSON encode the data field for each issue
                foreach ($chunkArray as &$issue) {
                    if (isset($issue['data']) && is_array($issue['data'])) {
                        $issue['data'] = json_encode($issue['data']);
                    }
                }
                SeoIssue::insert($chunkArray);
            });
        }
    }

    protected function detectIssuesForPage(SeoCrawlResult $result, Collection $allResults): Collection
    {
        $issues = collect();

        // Skip if no HTML content or failed status
        if ($result->status_code < 200 || $result->status_code >= 300) {
            Log::debug("SEO Issue Detection: Skipping {$result->url} - status code {$result->status_code}");
            return $issues;
        }

        // Get HTML content from stored crawl result
        $htmlContent = $result->html_content;
        if (! $htmlContent) {
            Log::debug("SEO Issue Detection: Skipping {$result->url} - no HTML content");
            return $issues;
        }

        Log::debug("SEO Issue Detection: Processing {$result->url} with " . strlen($htmlContent) . " bytes of HTML");

        // Create DOM document for parsing
        $dom = new \DOMDocument;
        // Suppress warnings but enable error reporting for debugging
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);
        $xpath = new DOMXPath($dom);

        // Detect various issues
        $issues = $issues->merge($this->detectBrokenInternalLinks($result, $xpath, $allResults));
        $issues = $issues->merge($this->detectRedirectIssues($result));
        $issues = $issues->merge($this->detectCanonicalIssues($result, $allResults));
        $issues = $issues->merge($this->detectHttpsIssues($result, $xpath));
        $issues = $issues->merge($this->detectMetaDescriptionIssues($result));
        $issues = $issues->merge($this->detectH1Issues($result, $xpath));
        $issues = $issues->merge($this->detectLargeImages($result, $xpath));
        $issues = $issues->merge($this->detectSlowResponse($result));
        $issues = $issues->merge($this->detectMissingAltText($result, $xpath));
        $issues = $issues->merge($this->detectTitleIssues($result));
        $issues = $issues->merge($this->detectInternalLinkCount($result));
        $issues = $issues->merge($this->detectTooManyInternalLinks($result));

        return $issues;
    }

    protected function detectCrossPageIssues(Collection $allResults): Collection
    {
        $issues = collect();

        // Detect duplicate content/titles
        $issues = $issues->merge($this->detectDuplicateContent($allResults));
        // Disabled orphaned page detection as it's not accurate
        // $issues = $issues->merge($this->detectOrphanedPages($allResults));

        return $issues;
    }

    protected function detectBrokenInternalLinks(SeoCrawlResult $result, DOMXPath $xpath, Collection $allResults): Collection
    {
        $issues = collect();
        $internalLinks = $result->internal_links ?? [];

        foreach ($internalLinks as $link) {
            $linkUrl = $link['url'] ?? '';
            if (empty($linkUrl)) {
                continue;
            }

            // Check if the linked URL was crawled and has an error status
            $linkedResult = $allResults->firstWhere('url', $linkUrl);
            if ($linkedResult && $linkedResult->isError()) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'broken_internal_link',
                    'severity' => SeoIssueSeverity::Error->value,
                    'url' => $result->url,
                    'title' => 'Broken Internal Link',
                    'description' => "Internal link to '{$linkUrl}' returns {$linkedResult->status_code} error",
                    'data' => [
                        'broken_url' => $linkUrl,
                        'status_code' => $linkedResult->status_code,
                        'link_text' => $link['text'] ?? '',
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $issues;
    }

    protected function detectRedirectIssues(SeoCrawlResult $result): Collection
    {
        $issues = collect();

        if ($result->isRedirect()) {
            $location = $result->headers['location'] ?? '';

            // Check for redirect loops (simplified - would need more sophisticated detection)
            if ($location === $result->url) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'redirect_loop',
                    'severity' => SeoIssueSeverity::Error->value,
                    'url' => $result->url,
                    'title' => 'Redirect Loop Detected',
                    'description' => 'Page redirects to itself, creating an infinite loop',
                    'data' => [
                        'redirect_to' => $location,
                        'status_code' => $result->status_code,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $issues;
    }

    protected function detectCanonicalIssues(SeoCrawlResult $result, Collection $allResults): Collection
    {
        $issues = collect();

        if ($result->canonical_url) {
            // Check if canonical URL points to a non-200 page
            $canonicalResult = $allResults->firstWhere('url', $result->canonical_url);
            if ($canonicalResult && ! $canonicalResult->isSuccess()) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'canonical_error',
                    'severity' => SeoIssueSeverity::Error->value,
                    'url' => $result->url,
                    'title' => 'Canonical URL Error',
                    'description' => "Canonical URL '{$result->canonical_url}' returns {$canonicalResult->status_code} error",
                    'data' => [
                        'canonical_url' => $result->canonical_url,
                        'status_code' => $canonicalResult->status_code,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $issues;
    }

    protected function detectHttpsIssues(SeoCrawlResult $result, DOMXPath $xpath): Collection
    {
        $issues = collect();

        // Check if page is HTTPS but has mixed content
        if (str_starts_with($result->url, 'https://')) {
            $mixedContentElements = $xpath->query('//img[@src[starts-with(., "http://")]] | //script[@src[starts-with(., "http://")]] | //link[@href[starts-with(., "http://")]]');

            if ($mixedContentElements->length > 0) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'mixed_content',
                    'severity' => SeoIssueSeverity::Error->value,
                    'url' => $result->url,
                    'title' => 'Mixed Content Detected',
                    'description' => "HTTPS page contains {$mixedContentElements->length} HTTP resources",
                    'data' => [
                        'mixed_content_count' => $mixedContentElements->length,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Check if HTTP page should redirect to HTTPS
        if (str_starts_with($result->url, 'http://') && $result->status_code === 200) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'http_not_redirected',
                'severity' => SeoIssueSeverity::Error->value,
                'url' => $result->url,
                'title' => 'HTTP Not Redirected to HTTPS',
                'description' => 'HTTP page should redirect to HTTPS for security',
                'data' => [],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectMetaDescriptionIssues(SeoCrawlResult $result): Collection
    {
        $issues = collect();

        if (empty($result->meta_description)) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'missing_meta_description',
                'severity' => SeoIssueSeverity::Warning->value,
                'url' => $result->url,
                'title' => 'Missing Meta Description',
                'description' => 'Page is missing a meta description tag',
                'data' => [],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectH1Issues(SeoCrawlResult $result, DOMXPath $xpath): Collection
    {
        $issues = collect();

        $h1Count = $xpath->query('//h1')->length;

        if ($h1Count === 0) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'missing_h1',
                'severity' => SeoIssueSeverity::Warning->value,
                'url' => $result->url,
                'title' => 'Missing H1 Tag',
                'description' => 'Page is missing an H1 heading tag',
                'data' => [],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($h1Count > 1) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'duplicate_h1',
                'severity' => SeoIssueSeverity::Warning->value,
                'url' => $result->url,
                'title' => 'Multiple H1 Tags',
                'description' => "Page has {$h1Count} H1 tags, should have only one",
                'data' => [
                    'h1_count' => $h1Count,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectLargeImages(SeoCrawlResult $result, DOMXPath $xpath): Collection
    {
        $issues = collect();

        $imageNodes = $xpath->query('//img[@src]');
        $largeImageCount = 0;

        foreach ($imageNodes as $node) {
            /** @var \DOMElement $node */
            $src = $node->getAttribute('src');
            if (empty($src)) {
                continue;
            }

            // Resolve relative URLs
            $imageUrl = $this->resolveUrl($src, $result->url);

            // Check image size (simplified - would need actual HTTP request)
            // For now, we'll check if it's a large file based on URL patterns
            if ($this->isLikelyLargeImage($imageUrl)) {
                $largeImageCount++;
            }
        }

        if ($largeImageCount > 0) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'large_images',
                'severity' => SeoIssueSeverity::Warning->value,
                'url' => $result->url,
                'title' => 'Large Images Detected',
                'description' => "Page contains {$largeImageCount} potentially large images (>2MB)",
                'data' => [
                    'large_image_count' => $largeImageCount,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectSlowResponse(SeoCrawlResult $result): Collection
    {
        $issues = collect();

        if ($result->response_time_ms > 1000) { // > 1 second
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'slow_response',
                'severity' => SeoIssueSeverity::Warning->value,
                'url' => $result->url,
                'title' => 'Slow Server Response',
                'description' => "Server response time is {$result->response_time_ms}ms (>1s TTFB)",
                'data' => [
                    'response_time_ms' => $result->response_time_ms,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectMissingAltText(SeoCrawlResult $result, DOMXPath $xpath): Collection
    {
        $issues = collect();

        $imageNodes = $xpath->query('//img[@src]');
        $missingAltCount = 0;

        foreach ($imageNodes as $node) {
            /** @var \DOMElement $node */
            $alt = $node->getAttribute('alt');
            if (empty($alt)) {
                $missingAltCount++;
            }
        }

        if ($missingAltCount > 0) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'missing_alt_text',
                'severity' => SeoIssueSeverity::Notice->value,
                'url' => $result->url,
                'title' => 'Missing Alt Text',
                'description' => "{$missingAltCount} images are missing alt text",
                'data' => [
                    'missing_alt_count' => $missingAltCount,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectTitleIssues(SeoCrawlResult $result): Collection
    {
        $issues = collect();

        if (empty($result->title)) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'missing_title',
                'severity' => SeoIssueSeverity::Error->value,
                'url' => $result->url,
                'title' => 'Missing Title Tag',
                'description' => 'Page is missing a title tag',
                'data' => [],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $titleLength = strlen($result->title);

            if ($titleLength < 30) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'title_too_short',
                    'severity' => SeoIssueSeverity::Notice->value,
                    'url' => $result->url,
                    'title' => 'Title Too Short',
                    'description' => "Title is only {$titleLength} characters (recommended: 30-60)",
                    'data' => [
                        'title_length' => $titleLength,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($titleLength > 60) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'title_too_long',
                    'severity' => SeoIssueSeverity::Notice->value,
                    'url' => $result->url,
                    'title' => 'Title Too Long',
                    'description' => "Title is {$titleLength} characters (recommended: 30-60)",
                    'data' => [
                        'title_length' => $titleLength,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $issues;
    }

    protected function detectInternalLinkCount(SeoCrawlResult $result): Collection
    {
        $issues = collect();

        $internalLinkCount = $result->internal_link_count ?? 0;

        if ($internalLinkCount < 3) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'too_few_internal_links',
                'severity' => SeoIssueSeverity::Notice->value,
                'url' => $result->url,
                'title' => 'Too Few Internal Links',
                'description' => "Page has only {$internalLinkCount} internal links (recommended: 3+)",
                'data' => [
                    'internal_link_count' => $internalLinkCount,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function detectDuplicateContent(Collection $allResults): Collection
    {
        $issues = collect();

        // Group by title to find duplicates
        $titleGroups = $allResults->groupBy('title');

        foreach ($titleGroups as $title => $results) {
            if (empty($title) || $results->count() <= 1) {
                continue;
            }

            $urls = $results->pluck('url')->toArray();

            foreach ($results as $result) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'duplicate_title',
                    'severity' => SeoIssueSeverity::Error->value,
                    'url' => $result->url,
                    'title' => 'Duplicate Title',
                    'description' => "Title '{$title}' is used on multiple pages",
                    'data' => [
                        'duplicate_title' => $title,
                        'duplicate_urls' => $urls,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $issues;
    }

    protected function detectOrphanedPages(Collection $allResults): Collection
    {
        $issues = collect();

        // Get all internal links from all pages
        $allInternalLinks = collect();
        foreach ($allResults as $result) {
            $internalLinks = $result->internal_links ?? [];
            foreach ($internalLinks as $link) {
                $allInternalLinks->push($link['url'] ?? '');
            }
        }

        $linkedUrls = $allInternalLinks->unique()->filter()->toArray();
        $homepageUrl = $allResults->first()->url ?? '';

        // Only check pages that are likely to be content pages (not admin, API, etc.)
        $contentPages = $allResults->filter(function ($result) use ($homepageUrl) {
            $url = $result->url;

            // Skip homepage
            if ($url === $homepageUrl) {
                return false;
            }

            // Skip admin, API, auth pages
            if (
                str_contains($url, '/admin') ||
                str_contains($url, '/api/') ||
                str_contains($url, '/login') ||
                str_contains($url, '/register') ||
                str_contains($url, '/logout') ||
                str_contains($url, '/dashboard') ||
                str_contains($url, '/profile') ||
                str_contains($url, '/settings')
            ) {
                return false;
            }

            // Skip pages with query parameters or fragments
            if (str_contains($url, '?') || str_contains($url, '#')) {
                return false;
            }

            return true;
        });

        // Check all content pages for accurate orphaned page detection
        // Note: This might create more issues but will be more accurate
        $pagesToCheck = $contentPages;

        foreach ($pagesToCheck as $result) {
            if (! in_array($result->url, $linkedUrls)) {
                $issues->push([
                    'seo_check_id' => $result->seo_check_id,
                    'seo_crawl_result_id' => $result->id,
                    'type' => 'orphaned_page',
                    'severity' => SeoIssueSeverity::Notice->value, // Changed to Notice since it's less critical
                    'url' => $result->url,
                    'title' => 'Orphaned Page',
                    'description' => 'Page is not linked to by any other internal page',
                    'data' => [],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $issues;
    }

    protected function detectTooManyInternalLinks(SeoCrawlResult $result): Collection
    {
        $issues = collect();

        $internalLinkCount = count($result->internal_links ?? []);

        // Flag pages with more than 100 internal links as potentially problematic
        if ($internalLinkCount > 100) {
            $issues->push([
                'seo_check_id' => $result->seo_check_id,
                'seo_crawl_result_id' => $result->id,
                'type' => 'too_many_internal_links',
                'severity' => SeoIssueSeverity::Notice->value,
                'url' => $result->url,
                'title' => 'Too Many Internal Links',
                'description' => "Page has {$internalLinkCount} internal links, which may dilute link equity",
                'data' => [
                    'link_count' => $internalLinkCount,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issues;
    }

    protected function getHtmlContent(string $url): ?string
    {
        try {
            $response = $this->httpClient->get($url);

            return $response->getBody()->getContents();
        } catch (RequestException $e) {
            return null;
        }
    }

    protected function resolveUrl(string $url, string $baseUrl): string
    {
        // Handle absolute URLs
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Handle protocol-relative URLs
        if (str_starts_with($url, '//')) {
            return parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $url;
        }

        // Handle relative URLs
        $baseParts = parse_url($baseUrl);
        $basePath = $baseParts['path'] ?? '/';

        if (str_starts_with($url, '/')) {
            return $baseParts['scheme'] . '://' . $baseParts['host'] . $url;
        }

        // Handle relative paths
        $baseDir = dirname($basePath);
        if ($baseDir === '.') {
            $baseDir = '/';
        }

        return $baseParts['scheme'] . '://' . $baseParts['host'] . $baseDir . '/' . $url;
    }

    protected function isLikelyLargeImage(string $url): bool
    {
        // Simple heuristic - check file extension and URL patterns
        $largeExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.bmp', '.tiff'];
        $urlLower = strtolower($url);

        foreach ($largeExtensions as $ext) {
            if (str_contains($urlLower, $ext)) {
                // Check for patterns that might indicate large images
                if (
                    str_contains($urlLower, 'high-res') ||
                    str_contains($urlLower, 'fullsize') ||
                    str_contains($urlLower, 'original') ||
                    str_contains($urlLower, 'large')
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
