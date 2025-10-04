<?php

namespace App\Crawlers;

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

        // Update progress
        $this->crawledCount++;
        $this->seoCheck->update([
            'total_urls_crawled' => $this->crawledCount,
        ]);

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

        // Update SEO check status to completed
        $this->seoCheck->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        Log::info("SEO Crawler: Crawl completed for website {$this->seoCheck->website->url}");
    }

    protected function extractSeoData(string $url, ResponseInterface $response): array
    {
        $startTime = microtime(true);
        $statusCode = $response->getStatusCode();
        $headers = $this->extractHeaders($response);
        $body = $response->getBody()->getContents();
        $bodySize = strlen($body);

        // Measure response time
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Initialize DOM variables
        $dom = null;
        $xpath = null;

        // Parse HTML content only if body is not empty
        if (! empty($body)) {
            $dom = new DOMDocument;
            @$dom->loadHTML($body);
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
}
