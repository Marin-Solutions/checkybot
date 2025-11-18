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

    protected int $maxUrls = 1000; // Limit to prevent infinite crawling

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
    }

    public function willCrawl(UriInterface $url, ?string $linkText): void
    {
        // Check if URL is allowed by robots.txt
        if (! $this->robotsSitemapService->isUrlAllowed((string) $url)) {
            Log::info("SEO Crawler: Skipping {$url} - disallowed by robots.txt");

            return;
        }

        // Check if we've reached the maximum URLs limit
        if ($this->crawledCount >= $this->maxUrls) {
            Log::info("SEO Crawler: Reached maximum URLs limit ({$this->maxUrls}), skipping {$url}");

            return;
        }

        // Update progress
        $this->crawledCount++;

        // Only update total_crawlable_urls if it's still at the initial value (1) or if we need to increase it significantly
        $currentTotal = $this->seoCheck->total_crawlable_urls;
        $newTotal = $currentTotal;

        // If we're still at the initial estimate (1), set a reasonable estimate
        if ($currentTotal <= 1) {
            $newTotal = max(50, $this->crawledCount * 2); // Start with 50 or 2x crawled count
        } elseif ($this->crawledCount > $currentTotal) {
            // Only increase if we've exceeded the current total by a significant margin
            $newTotal = max($currentTotal, $this->crawledCount + 20); // Add buffer of 20 URLs
        }

        $this->seoCheck->update([
            'total_urls_crawled' => $this->crawledCount,
            'total_crawlable_urls' => $newTotal,
        ]);

        // Broadcast progress update (every 5 URLs)
        if ($this->crawledCount % 5 === 0 || $this->crawledCount === 1) {
            $this->broadcastProgress((string) $url);
        }

        Log::info("SEO Crawler: Crawling {$url} (URL #{$this->crawledCount})");
    }

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $urlString = (string) $url;

        // Skip if we've reached the maximum URLs limit
        if ($this->crawledCount >= $this->maxUrls) {
            return;
        }

        try {
            $crawlData = $this->extractSeoData($urlString, $response);
            $this->crawlResults[] = $crawlData;

            // Extract and categorize links
            $this->extractLinks($crawlData['internal_links'], $crawlData['external_links']);
        } catch (\Exception $e) {
            Log::error("SEO Crawler: Error processing {$urlString}: ".$e->getMessage());

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
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $urlString = (string) $url;

        Log::warning("SEO Crawler: Failed to crawl {$urlString}: ".$requestException->getMessage());

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
            'total_crawlable_urls' => $this->crawledCount, // Set final total to actual crawled count
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
        // Get response body content - handle stream consumption properly
        $body = '';
        $bodySize = 0;

        try {
            // Get the response body stream
            $stream = $response->getBody();

            // Check if stream is readable and has content
            if ($stream->isReadable() && $stream->getSize() > 0) {
                // Rewind to beginning if possible
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }

                // Read the content
                $body = $stream->getContents();
                $bodySize = strlen($body);

                // If we got empty content, try alternative method
                if ($bodySize === 0) {
                    $body = (string) $response->getBody();
                    $bodySize = strlen($body);
                }
            } else {
                Log::warning("SEO Crawler: Response stream not readable or empty for {$url}");
                $body = '';
                $bodySize = 0;
            }
        } catch (\Exception $e) {
            Log::error("SEO Crawler: Error reading response body for {$url}: ".$e->getMessage());
            $body = '';
            $bodySize = 0;
        }

        // Debug logging
        if ($bodySize === 0) {
            Log::warning("SEO Crawler: Empty response body for {$url} (Status: {$statusCode})");
        } else {
            Log::info("SEO Crawler: Response body size for {$url}: {$bodySize} bytes");
        }

        // Measure response time
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Initialize DOM variables
        $dom = null;
        $xpath = null;

        // Parse HTML content only if body is not empty
        if (! empty($body)) {
            $dom = new DOMDocument;
            // Suppress warnings but enable error reporting for debugging
            libxml_use_internal_errors(true);
            $dom->loadHTML($body);
            $xpath = new DOMXPath($dom);
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

        // Truncate HTML content to prevent MySQL packet size errors (max 500KB)
        $maxHtmlSize = 500 * 1024; // 500KB limit
        $truncatedHtml = $body;
        if (strlen($body) > $maxHtmlSize) {
            $truncatedHtml = substr($body, 0, $maxHtmlSize);
            Log::warning("SEO Crawler: Truncated HTML content for {$url} from ".strlen($body)." to {$maxHtmlSize} bytes");
        }

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
            'html_content' => $truncatedHtml, // Store truncated HTML content for issue detection
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
        if ($titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }

        // Try og:title as fallback
        $ogNodes = $xpath->query('//meta[@property="og:title"]/@content');
        if ($ogNodes->length > 0) {
            return trim($ogNodes->item(0)->textContent);
        }

        return null;
    }

    protected function extractMetaDescription(DOMXPath $xpath): ?string
    {
        // Try different meta description formats
        $metaNodes = $xpath->query('//meta[@name="description"]/@content');
        if ($metaNodes->length > 0) {
            return trim($metaNodes->item(0)->textContent);
        }

        // Try property="og:description"
        $ogNodes = $xpath->query('//meta[@property="og:description"]/@content');
        if ($ogNodes->length > 0) {
            return trim($ogNodes->item(0)->textContent);
        }

        return null;
    }

    protected function extractH1(DOMXPath $xpath): ?string
    {
        $h1Nodes = $xpath->query('//h1');
        if ($h1Nodes->length > 0) {
            return trim($h1Nodes->item(0)->textContent);
        }

        return null;
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
            return parse_url($baseUrl, PHP_URL_SCHEME).':'.$url;
        }

        // Handle relative URLs
        $baseParts = parse_url($baseUrl);
        $basePath = $baseParts['path'] ?? '/';

        if (str_starts_with($url, '/')) {
            return $baseParts['scheme'].'://'.$baseParts['host'].$url;
        }

        // Handle relative paths
        $baseDir = dirname($basePath);
        if ($baseDir === '.') {
            $baseDir = '/';
        }

        return $baseParts['scheme'].'://'.$baseParts['host'].$baseDir.'/'.$url;
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
            $totalUrls = $this->seoCheck->total_crawlable_urls;

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
                        etaSeconds: null
                    ));
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast progress update: '.$e->getMessage());
                    // Continue crawling even if broadcast fails
                }

                $this->lastBroadcastCount = $this->crawledCount;
            }
        } catch (\Exception $e) {
            // Log the error but don't let it stop the crawling process
            $this->broadcastFailures++;
            Log::warning("SEO Crawler: Failed to broadcast progress update ({$this->broadcastFailures} failures): ".$e->getMessage());

            // Disable broadcasting after 5 consecutive failures
            if ($this->broadcastFailures >= 5) {
                Log::warning("SEO Crawler: Disabling broadcasting after {$this->broadcastFailures} consecutive failures");
            }
        }
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

            // Count URLs with HTTP errors (4xx, 5xx)
            $httpErrorUrls = $this->seoCheck->crawlResults()
                ->where('status_code', '>=', 400)
                ->where('status_code', '<', 600)
                ->count();

            // Count unique URLs with SEO errors
            $seoErrorUrls = $this->seoCheck->seoIssues()
                ->where('severity', 'error')
                ->distinct('url')
                ->count('url');

            // Total URLs with any type of error
            $urlsWithErrors = $httpErrorUrls + $seoErrorUrls;

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
            Log::error('SEO Crawler: Failed to populate computed columns: '.$e->getMessage());
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
                Log::warning('Failed to broadcast completion event: '.$e->getMessage());
                // Continue even if broadcast fails
            }
        } catch (\Exception $e) {
            // Log the error but don't let it stop the completion process
            Log::warning('SEO Crawler: Failed to broadcast completion event: '.$e->getMessage());
        }
    }
}
