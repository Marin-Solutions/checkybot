<?php

namespace App\Crawlers;

use App\Events\CrawlCompleted;
use App\Events\CrawlProgressUpdated;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Services\RobotsSitemapService;
use App\Services\SeoIssueDetectionService;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class SeoHealthCheckCrawler extends CrawlObserver
{
    protected SeoCheck $seoCheck;

    protected array $crawlResults = [];

    protected array $internalLinks = [];

    protected array $externalLinks = [];

    protected string $baseDomain;

    protected int $crawledCount = 0;

    protected int $discoveredCount = 0; // Track total discovered URLs for dynamic discovery

    protected int $estimatedTotal = 0; // Stable estimated total that only increases

    protected int $maxUrls = 200; // Reasonable limit based on previous working version

    protected int $maxCrawlTime = 300; // 5 minutes max crawl time

    protected float $startedAt; // Track crawl start time for ETC calculation

    protected ?float $lastCrawlTime = null; // Track last crawl time for duration calculation

    protected array $lastTimes = []; // Moving average for more stable ETA

    protected int $lastBroadcastCount = 0; // Track when we last broadcasted

    protected int $broadcastFailures = 0; // Track broadcast failures

    protected RobotsSitemapService $robotsSitemapService;

    protected SeoIssueDetectionService $issueDetectionService;

    public function __construct(SeoCheck $seoCheck)
    {
        $this->seoCheck = $seoCheck;
        $this->baseDomain = parse_url($seoCheck->website->url, PHP_URL_HOST);
        $this->robotsSitemapService = app(RobotsSitemapService::class);
        $this->issueDetectionService = app(SeoIssueDetectionService::class);
        $this->startedAt = microtime(true); // Initialize start time for ETC calculation
    }

    public function willCrawl(UriInterface $url, ?string $linkText): void
    {
        Log::info("SEO Crawler: willCrawl called for URL: {$url}");

        // Check if URL is allowed by robots.txt
        if (! $this->robotsSitemapService->isUrlAllowed((string) $url)) {
            Log::info("SEO Crawler: Skipping {$url} - disallowed by robots.txt");

            return;
        }

        // Skip if we've reached the maximum URLs limit
        if ($this->discoveredCount >= $this->maxUrls) {
            Log::info("SEO Crawler: Reached maximum discovered URLs limit ({$this->maxUrls}), skipping {$url}");
            return;
        }

        // Skip if we've exceeded the maximum crawl time
        $elapsedTime = time() - $this->startedAt;
        if ($elapsedTime >= $this->maxCrawlTime) {
            Log::info("SEO Crawler: Reached maximum crawl time ({$this->maxCrawlTime}s), skipping {$url}");
            return;
        }

        // Update discovered count for dynamic discovery (URLs found but not yet crawled)
        $this->discoveredCount++;

        // For dynamic discovery, estimate total URLs based on discovery rate
        $crawlStrategy = $this->seoCheck->crawl_summary['crawl_strategy'] ?? 'dynamic_discovery';
        if ($crawlStrategy === 'dynamic_discovery') {
            // Calculate new estimated total
            $newEstimatedTotal = $this->estimateTotalUrls();

            // Only update if the new estimate is higher (to avoid decreasing totals)
            if ($newEstimatedTotal > $this->estimatedTotal) {
                $this->estimatedTotal = $newEstimatedTotal;
                $this->seoCheck->update([
                    'total_crawlable_urls' => $this->estimatedTotal,
                ]);
            }
        }

        // Broadcast progress update (every 5 URLs or on first URL)
        if ($this->crawledCount % 5 === 0 || $this->crawledCount === 1) {
            $this->broadcastProgress((string) $url);
        }

        Log::info("SEO Crawler: Crawling {$url} (URL #{$this->crawledCount}/{$this->discoveredCount})");
    }

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $urlString = (string) $url;
        Log::info("SEO Crawler: crawled called for URL: {$urlString}");

        // Skip if we've reached the maximum URLs limit
        if ($this->crawledCount >= $this->maxUrls) {
            Log::info("SEO Crawler: Reached maximum URLs limit ({$this->maxUrls}), skipping {$urlString}");
            return;
        }

        // Skip if we've exceeded the maximum crawl time
        $elapsedTime = time() - $this->startedAt;
        if ($elapsedTime >= $this->maxCrawlTime) {
            Log::info("SEO Crawler: Reached maximum crawl time ({$this->maxCrawlTime}s), skipping {$urlString}");
            return;
        }

        // Increment crawled count (URLs actually processed)
        $this->crawledCount++;

        // Calculate timing for ETC
        $now = microtime(true);
        $duration = $now - ($this->lastCrawlTime ?? $this->startedAt);
        $this->lastCrawlTime = $now;

        // Add to moving average (keep last 10 times for stability)
        $this->lastTimes[] = $duration;
        if (count($this->lastTimes) > 10) {
            array_shift($this->lastTimes);
        }

        // Update database with crawled count
        $this->seoCheck->update([
            'total_urls_crawled' => $this->crawledCount,
        ]);

        try {
            $crawlData = $this->extractSeoData($urlString, $response);
            $this->crawlResults[] = $crawlData;

            // Extract and categorize links
            $this->extractLinks($crawlData['internal_links'], $crawlData['external_links']);
        } catch (\Exception $e) {
            Log::error("SEO Crawler: Error processing {$urlString}: " . $e->getMessage());

            // Still record the failed crawl
            $this->crawlResults[] = [
                'seo_check_id' => $this->seoCheck->id,
                'url' => $urlString,
                'status_code' => $response->getStatusCode(),
                'robots_txt_allowed' => $this->robotsSitemapService->isUrlAllowed($urlString),
                'crawl_source' => 'discovery',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Broadcast progress with ETC calculation (every 5 URLs or on first URL)
        if ($this->crawledCount % 5 === 0 || $this->crawledCount === 1) {
            $this->broadcastProgress($urlString);
        }
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $urlString = (string) $url;

        Log::warning("SEO Crawler: Failed to crawl {$urlString}: " . $requestException->getMessage());

        // Record failed crawl
        $this->crawlResults[] = [
            'seo_check_id' => $this->seoCheck->id,
            'url' => $urlString,
            'status_code' => $requestException->getCode() ?: 0,
            'robots_txt_allowed' => $this->robotsSitemapService->isUrlAllowed($urlString),
            'crawl_source' => 'discovery',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function finishedCrawling(): void
    {
        Log::info("SEO Crawler: Finished crawling. Processed {$this->crawledCount} URLs.");

        // Check if we actually crawled any URLs successfully
        if ($this->crawledCount === 0) {
            Log::warning("SEO Crawler: No URLs were successfully crawled. Marking as failed.");
            $this->seoCheck->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);
            return;
        }

        // Bulk insert crawl results
        if (! empty($this->crawlResults)) {
            foreach ($this->crawlResults as $result) {
                SeoCrawlResult::create($result);
            }
        }

        // Detect and classify issues
        Log::info('SEO Crawler: Starting issue detection and classification...');
        $this->issueDetectionService->detectIssues($this->seoCheck);
        Log::info('SEO Crawler: Issue detection completed.');

        // Populate computed columns for performance
        Log::info('SEO Crawler: Populating computed columns...');
        $this->populateComputedColumns();
        Log::info('SEO Crawler: Computed columns populated.');

        // Update SEO check status to completed
        $this->seoCheck->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        // Broadcast completion event
        $this->broadcastCompletion();

        Log::info("SEO Crawler: Crawl completed for website {$this->seoCheck->website->url}");
    }

    protected function extractSeoData(string $url, ResponseInterface $response): array
    {
        $startTime = microtime(true);
        $statusCode = $response->getStatusCode();
        $headers = $this->extractHeaders($response);

        // Read response body once and store it
        $bodyStream = $response->getBody();
        $bodyStream->rewind(); // Ensure we're at the beginning of the stream
        $body = $bodyStream->getContents();
        $bodySize = strlen($body);

        // Debug logging for response body
        if ($statusCode === 200 && $bodySize === 0) {
            Log::warning("SEO Crawler: HTTP 200 response but empty body for {$url}");
        } elseif ($statusCode === 200 && $bodySize > 0) {
            Log::info("SEO Crawler: HTTP 200 response with {$bodySize} bytes for {$url}");
        }

        // Measure response time
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Initialize DOM variables
        $dom = null;
        $xpath = null;

        // Parse HTML content only if body is not empty
        if (! empty($body)) {
            try {
                $dom = new DOMDocument;
                // Use LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD to avoid issues
                @$dom->loadHTML($body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $xpath = new DOMXPath($dom);
            } catch (\Exception $e) {
                Log::warning("SEO Crawler: Failed to parse HTML for {$url}: " . $e->getMessage());
            }
        } else {
            Log::warning("SEO Crawler: Empty response body for {$url} (status: {$statusCode})");
        }

        // Extract SEO data
        $title = $xpath ? $this->extractTitle($xpath) : null;
        $metaDescription = $xpath ? $this->extractMetaDescription($xpath) : null;
        $h1 = $xpath ? $this->extractH1($xpath) : null;
        $canonicalUrl = $xpath ? $this->extractCanonicalUrl($xpath) : null;

        // Extract links
        $internalLinks = $xpath ? $this->extractInternalLinks($xpath, $url) : [];
        $externalLinks = $xpath ? $this->extractExternalLinks($xpath, $url) : [];

        // Extract resource sizes
        $resourceSizes = $xpath ? $this->extractResourceSizes($xpath, $url, $body) : [];

        // Count images
        $imageCount = $xpath ? $xpath->query('//img')->length : 0;

        return [
            'seo_check_id' => $this->seoCheck->id,
            'url' => $url,
            'status_code' => $statusCode,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'meta_description' => $metaDescription,
            'h1' => $h1,
            'internal_links' => $internalLinks,
            'external_links' => $externalLinks,
            'page_size_bytes' => $bodySize,
            'html_size_bytes' => $bodySize,
            'html_content' => $body, // Store the HTML content for issue detection
            'resource_sizes' => $resourceSizes,
            'headers' => $headers,
            'response_time_ms' => round($responseTime, 2),
            'internal_link_count' => count($internalLinks),
            'external_link_count' => count($externalLinks),
            'image_count' => $imageCount,
            'robots_txt_allowed' => $this->robotsSitemapService->isUrlAllowed($url),
            'crawl_source' => 'discovery',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    protected function extractHeaders(ResponseInterface $response): array
    {
        $headers = [];
        $importantHeaders = [
            'x-robots-tag',
            'content-type',
            'content-length',
            'location',
            'link',
        ];

        foreach ($importantHeaders as $header) {
            if ($response->hasHeader($header)) {
                $headers[$header] = $response->getHeaderLine($header);
            }
        }

        return $headers;
    }

    protected function extractTitle(DOMXPath $xpath): ?string
    {
        $titleNodes = $xpath->query('//title');

        return $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : null;
    }

    protected function extractMetaDescription(DOMXPath $xpath): ?string
    {
        $metaNodes = $xpath->query('//meta[@name="description"]/@content');

        return $metaNodes->length > 0 ? trim($metaNodes->item(0)->textContent) : null;
    }

    protected function extractH1(DOMXPath $xpath): ?string
    {
        $h1Nodes = $xpath->query('//h1');

        return $h1Nodes->length > 0 ? trim($h1Nodes->item(0)->textContent) : null;
    }

    protected function extractCanonicalUrl(DOMXPath $xpath): ?string
    {
        $canonicalNodes = $xpath->query('//link[@rel="canonical"]/@href');

        return $canonicalNodes->length > 0 ? trim($canonicalNodes->item(0)->textContent) : null;
    }

    protected function extractInternalLinks(DOMXPath $xpath, string $currentUrl): array
    {
        $links = [];
        $linkNodes = $xpath->query('//a[@href]');

        foreach ($linkNodes as $node) {
            /** @var \DOMElement $node */
            $href = $node->getAttribute('href') ?? '';
            $text = trim($node->textContent ?? '');

            if ($this->isInternalLink($href, $currentUrl)) {
                $links[] = [
                    'url' => $this->resolveUrl($href, $currentUrl),
                    'text' => $text,
                ];
            }
        }

        return $links;
    }

    protected function extractExternalLinks(DOMXPath $xpath, string $currentUrl): array
    {
        $links = [];
        $linkNodes = $xpath->query('//a[@href]');

        foreach ($linkNodes as $node) {
            /** @var \DOMElement $node */
            $href = $node->getAttribute('href') ?? '';
            $text = trim($node->textContent ?? '');

            if ($this->isExternalLink($href, $currentUrl)) {
                $links[] = [
                    'url' => $this->resolveUrl($href, $currentUrl),
                    'text' => $text,
                ];
            }
        }

        return $links;
    }

    protected function extractResourceSizes(DOMXPath $xpath, string $currentUrl, string $body): array
    {
        $resources = [];

        // Extract CSS files
        $cssNodes = $xpath->query('//link[@rel="stylesheet"]/@href');
        foreach ($cssNodes as $node) {
            $href = $node->textContent;
            $resources[] = [
                'type' => 'css',
                'url' => $this->resolveUrl($href, $currentUrl),
                'size' => 0, // Would need to fetch to get actual size
            ];
        }

        // Extract JS files
        $jsNodes = $xpath->query('//script[@src]/@src');
        foreach ($jsNodes as $node) {
            $src = $node->textContent;
            $resources[] = [
                'type' => 'js',
                'url' => $this->resolveUrl($src, $currentUrl),
                'size' => 0, // Would need to fetch to get actual size
            ];
        }

        // Extract images
        $imgNodes = $xpath->query('//img[@src]/@src');
        foreach ($imgNodes as $node) {
            $src = $node->textContent;
            $resources[] = [
                'type' => 'image',
                'url' => $this->resolveUrl($src, $currentUrl),
                'size' => 0, // Would need to fetch to get actual size
            ];
        }

        return $resources;
    }

    protected function isInternalLink(string $href, string $currentUrl): bool
    {
        if (empty($href) || str_starts_with($href, '#')) {
            return false;
        }

        $currentDomain = parse_url($currentUrl, PHP_URL_HOST);
        $linkDomain = parse_url($this->resolveUrl($href, $currentUrl), PHP_URL_HOST);

        return $currentDomain === $linkDomain;
    }

    protected function isExternalLink(string $href, string $currentUrl): bool
    {
        if (empty($href) || str_starts_with($href, '#')) {
            return false;
        }

        return ! $this->isInternalLink($href, $currentUrl);
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

    protected function extractLinks(array $internalLinks, array $externalLinks): void
    {
        foreach ($internalLinks as $link) {
            $this->internalLinks[] = $link['url'];
        }

        foreach ($externalLinks as $link) {
            $this->externalLinks[] = $link['url'];
        }
    }

    /**
     * Broadcast progress update to frontend
     */
    protected function broadcastProgress(string $currentUrl): void
    {
        // Skip broadcasting if we've had too many failures
        if ($this->broadcastFailures >= 5) {
            return;
        }

        try {
            $progress = $this->seoCheck->getProgressPercentage();
            $issuesFound = $this->seoCheck->seoIssues()->count();

            // Use stable estimated total for dynamic discovery, otherwise use total_crawlable_urls
            $crawlStrategy = $this->seoCheck->crawl_summary['crawl_strategy'] ?? 'dynamic_discovery';
            $totalUrls = $crawlStrategy === 'dynamic_discovery'
                ? max($this->estimatedTotal, $this->crawledCount + 10) // Use stable estimated total
                : $this->seoCheck->total_crawlable_urls;

            // Calculate ETC (Estimated Time to Completion)
            $etaSeconds = $this->calculateETA($totalUrls);

            // Update progress in database for persistence
            $this->seoCheck->update(['progress' => $progress]);

            // Only broadcast if we have new data to share
            if ($this->crawledCount > $this->lastBroadcastCount) {
                Log::info("Broadcasting progress update for SEO check {$this->seoCheck->id}: {$this->crawledCount}/{$totalUrls} URLs crawled");

                try {
                    broadcast(new CrawlProgressUpdated(
                        seoCheckId: $this->seoCheck->id,
                        urlsCrawled: $this->crawledCount,
                        totalUrls: $totalUrls,
                        issuesFound: $issuesFound,
                        progress: $progress,
                        currentUrl: $currentUrl,
                        etaSeconds: $etaSeconds
                    ));
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast progress update: ' . $e->getMessage());
                    // Continue crawling even if broadcast fails
                }

                $this->lastBroadcastCount = $this->crawledCount;
            }
        } catch (\Exception $e) {
            // Log the error but don't let it stop the crawling process
            $this->broadcastFailures++;
            Log::warning("SEO Crawler: Failed to broadcast progress update ({$this->broadcastFailures} failures): " . $e->getMessage());

            // Disable broadcasting after 5 consecutive failures
            if ($this->broadcastFailures >= 5) {
                Log::warning("SEO Crawler: Disabling broadcasting after {$this->broadcastFailures} consecutive failures");
            }
        }
    }

    /**
     * Estimate total URLs for dynamic discovery
     */
    protected function estimateTotalUrls(): int
    {
        // If we haven't crawled many URLs yet, use a conservative estimate
        if ($this->crawledCount < 10) {
            return max($this->discoveredCount, 100); // Start with reasonable estimate for small sites
        }

        // Calculate discovery rate (new URLs discovered per crawled URL)
        $discoveryRate = $this->discoveredCount / max(1, $this->crawledCount);

        // Estimate remaining URLs based on discovery rate
        // More conservative estimates for typical small-medium sites
        if ($discoveryRate > 1.5) {
            // Still discovering many new URLs, estimate 1.5-2x current discovered
            $estimatedTotal = (int) ($this->discoveredCount * 1.8);
        } elseif ($discoveryRate > 1.1) {
            // Moderate discovery, estimate 1.2-1.5x current discovered
            $estimatedTotal = (int) ($this->discoveredCount * 1.4);
        } else {
            // Low discovery rate, we're probably near the end
            $estimatedTotal = (int) ($this->discoveredCount * 1.1);
        }

        // Ensure we don't go below discovered count
        return max($this->discoveredCount, $estimatedTotal);
    }

    /**
     * Calculate Estimated Time to Completion (ETC)
     */
    protected function calculateETA(int $totalUrls): int
    {
        // Don't show ETA until we have enough data points for accuracy
        if ($this->crawledCount < 3 || empty($this->lastTimes)) {
            return 0;
        }

        // Use moving average for more stable ETA
        $avgTimePerUrl = array_sum($this->lastTimes) / count($this->lastTimes);
        $remainingUrls = max(0, $totalUrls - $this->crawledCount);

        // Calculate ETA in seconds
        $etaSeconds = (int) round($avgTimePerUrl * $remainingUrls);

        return $etaSeconds;
    }

    /**
     * Populate computed columns for performance
     */
    protected function populateComputedColumns(): void
    {
        try {
            // Count issues by type
            $errorsCount = $this->seoCheck->seoIssues()->where('severity', 'error')->count();
            $warningsCount = $this->seoCheck->seoIssues()->where('severity', 'warning')->count();
            $noticesCount = $this->seoCheck->seoIssues()->where('severity', 'notice')->count();

            // Count HTTP errors
            $httpErrorsCount = $this->seoCheck->crawlResults()
                ->where('status_code', '>=', 400)
                ->count();

            // Calculate health score
            $totalUrls = $this->seoCheck->total_urls_crawled;
            $urlsWithErrors = $this->seoCheck->crawlResults()
                ->where('status_code', '>=', 400)
                ->count();

            $healthScore = $totalUrls > 0 ? (($totalUrls - $urlsWithErrors) / $totalUrls) * 100 : 0;

            // Update computed columns
            $this->seoCheck->update([
                'computed_errors_count' => $errorsCount,
                'computed_warnings_count' => $warningsCount,
                'computed_notices_count' => $noticesCount,
                'computed_http_errors_count' => $httpErrorsCount,
                'computed_health_score' => round($healthScore, 2),
            ]);

            Log::info("SEO Crawler: Computed columns updated - Errors: {$errorsCount}, Warnings: {$warningsCount}, Notices: {$noticesCount}, Health Score: {$healthScore}%");
        } catch (\Exception $e) {
            Log::error('SEO Crawler: Failed to populate computed columns: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast completion event to frontend
     */
    protected function broadcastCompletion(): void
    {
        try {
            $this->seoCheck->refresh();
            $totalIssuesFound = $this->seoCheck->seoIssues()->count();
            $healthScore = $this->seoCheck->getHealthScoreAttribute();

            Log::info("Broadcasting crawl completion for SEO check {$this->seoCheck->id}: {$this->crawledCount} URLs crawled, {$totalIssuesFound} issues found, health score: {$healthScore}");

            try {
                broadcast(new CrawlCompleted(
                    seoCheckId: $this->seoCheck->id,
                    totalUrlsCrawled: $this->crawledCount,
                    totalIssuesFound: $totalIssuesFound,
                    healthScore: $healthScore
                ));
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast completion event: ' . $e->getMessage());
                // Continue even if broadcast fails
            }
        } catch (\Exception $e) {
            // Log the error but don't let it stop the completion process
            Log::warning('SEO Crawler: Failed to broadcast completion event: ' . $e->getMessage());
        }
    }
}
