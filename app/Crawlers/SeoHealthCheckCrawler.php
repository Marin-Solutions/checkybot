<?php

namespace App\Crawlers;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
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

    public function __construct(SeoCheck $seoCheck)
    {
        $this->seoCheck = $seoCheck;
        $this->baseDomain = parse_url($seoCheck->website->url, PHP_URL_HOST);
    }

    public function willCrawl(UriInterface $url, ?string $linkText): void
    {
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

        $startTime = microtime(true);

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
                'issues' => [['type' => 'crawl_error', 'message' => $e->getMessage()]],
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
            'issues' => [['type' => 'crawl_failed', 'message' => $requestException->getMessage()]],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function finishedCrawling(): void
    {
        Log::info("SEO Crawler: Finished crawling. Processed {$this->crawledCount} URLs.");

        // Bulk insert crawl results
        if (! empty($this->crawlResults)) {
            SeoCrawlResult::insert($this->crawlResults);
        }

        // Calculate health score and update SEO check
        $this->calculateHealthScore();

        Log::info("SEO Crawler: Health check completed for website {$this->seoCheck->website->url}");
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

        // Parse HTML content
        $dom = new DOMDocument;
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);

        // Extract SEO elements
        $title = $this->extractTitle($xpath);
        $metaDescription = $this->extractMetaDescription($xpath);
        $h1 = $this->extractH1($xpath);
        $canonicalUrl = $this->extractCanonicalUrl($xpath, $url);

        // Extract links
        $internalLinks = [];
        $externalLinks = [];
        $this->extractAllLinks($xpath, $url, $internalLinks, $externalLinks);

        // Extract resource sizes
        $resourceSizes = $this->extractResourceSizes($xpath, $url, $body);

        // Count images
        $imageCount = $xpath->query('//img')->length;

        // Detect issues
        $issues = $this->detectIssues($url, $statusCode, $title, $metaDescription, $h1, $headers, $responseTime, $bodySize);

        // Check for soft 404
        $isSoft404 = $this->isSoft404($statusCode, $body, $title);

        // Check for redirect loops
        $isRedirectLoop = $this->isRedirectLoop($url, $headers);

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
            'issues' => $issues,
            'internal_link_count' => count($internalLinks),
            'external_link_count' => count($externalLinks),
            'image_count' => $imageCount,
            'is_soft_404' => $isSoft404,
            'is_redirect_loop' => $isRedirectLoop,
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

    protected function extractCanonicalUrl(DOMXPath $xpath, string $currentUrl): ?string
    {
        $canonicalNodes = $xpath->query('//link[@rel="canonical"]/@href');
        if ($canonicalNodes->length > 0) {
            $canonical = $canonicalNodes->item(0)->textContent;
            // Convert relative URLs to absolute
            if (str_starts_with($canonical, '/')) {
                $parsedUrl = parse_url($currentUrl);
                $canonical = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $canonical;
            }

            return $canonical;
        }

        return null;
    }

    protected function extractAllLinks(DOMXPath $xpath, string $currentUrl, array &$internalLinks, array &$externalLinks): void
    {
        $linkNodes = $xpath->query('//a[@href]');
        $currentDomain = parse_url($currentUrl, PHP_URL_HOST);

        foreach ($linkNodes as $linkNode) {
            $href = $linkNode->getAttribute('href');
            $text = trim($linkNode->textContent);

            // Convert relative URLs to absolute
            if (str_starts_with($href, '/')) {
                $parsedUrl = parse_url($currentUrl);
                $href = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $href;
            } elseif (! str_starts_with($href, 'http')) {
                continue; // Skip mailto:, tel:, etc.
            }

            $linkDomain = parse_url($href, PHP_URL_HOST);

            if ($linkDomain === $currentDomain) {
                $internalLinks[] = ['url' => $href, 'text' => $text];
            } else {
                $externalLinks[] = ['url' => $href, 'text' => $text];
            }
        }
    }

    protected function extractLinks(array $internalLinks, array $externalLinks): void
    {
        $this->internalLinks = array_merge($this->internalLinks, $internalLinks);
        $this->externalLinks = array_merge($this->externalLinks, $externalLinks);
    }

    protected function extractResourceSizes(DOMXPath $xpath, string $baseUrl, string $html): array
    {
        $resourceSizes = [
            'images' => 0,
            'css' => 0,
            'js' => 0,
        ];

        // This is a simplified version - in a real implementation,
        // you would make HTTP requests to get actual resource sizes
        $imageNodes = $xpath->query('//img/@src');
        $cssNodes = $xpath->query('//link[@rel="stylesheet"]/@href');
        $jsNodes = $xpath->query('//script/@src');

        $resourceSizes['images'] = $imageNodes->length;
        $resourceSizes['css'] = $cssNodes->length;
        $resourceSizes['js'] = $jsNodes->length;

        return $resourceSizes;
    }

    protected function detectIssues(
        string $url,
        int $statusCode,
        ?string $title,
        ?string $metaDescription,
        ?string $h1,
        array $headers,
        float $responseTime,
        int $bodySize
    ): array {
        $issues = [];

        // Status code issues
        if ($statusCode >= 400) {
            $issues[] = [
                'type' => 'error',
                'category' => 'crawlability',
                'message' => "HTTP {$statusCode} status code",
            ];
        }

        // Missing title
        if (empty($title)) {
            $issues[] = [
                'type' => 'warning',
                'category' => 'onpage',
                'message' => 'Missing page title',
            ];
        }

        // Missing meta description
        if (empty($metaDescription)) {
            $issues[] = [
                'type' => 'warning',
                'category' => 'onpage',
                'message' => 'Missing meta description',
            ];
        }

        // Missing H1
        if (empty($h1)) {
            $issues[] = [
                'type' => 'warning',
                'category' => 'onpage',
                'message' => 'Missing H1 tag',
            ];
        }

        // Slow response time
        if ($responseTime > 1000) { // > 1 second
            $issues[] = [
                'type' => 'warning',
                'category' => 'technical',
                'message' => 'Slow response time (' . round($responseTime / 1000, 2) . 's)',
            ];
        }

        // Large page size
        if ($bodySize > 1024 * 1024) { // > 1MB
            $issues[] = [
                'type' => 'notice',
                'category' => 'technical',
                'message' => 'Large page size (' . round($bodySize / 1024, 2) . 'KB)',
            ];
        }

        // Noindex header
        if (isset($headers['x-robots-tag']) && str_contains(strtolower($headers['x-robots-tag']), 'noindex')) {
            $issues[] = [
                'type' => 'notice',
                'category' => 'indexability',
                'message' => 'Page has noindex directive',
            ];
        }

        return $issues;
    }

    protected function isSoft404(int $statusCode, string $body, ?string $title): bool
    {
        if ($statusCode === 200) {
            // Check for soft 404 indicators
            $soft404Indicators = [
                'page not found',
                '404 error',
                'not found',
                'page does not exist',
                'error 404',
            ];

            $bodyLower = strtolower($body);
            $titleLower = strtolower($title ?? '');

            foreach ($soft404Indicators as $indicator) {
                if (str_contains($bodyLower, $indicator) || str_contains($titleLower, $indicator)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isRedirectLoop(string $url, array $headers): bool
    {
        // Simple check for redirect loops - in a real implementation,
        // you would track the redirect chain
        if (isset($headers['location']) && $headers['location'] === $url) {
            return true;
        }

        return false;
    }

    protected function calculateHealthScore(): void
    {
        $totalUrls = $this->crawledCount;
        $errorUrls = 0;
        $warningCount = 0;
        $noticeCount = 0;

        // Count errors and other issues
        foreach ($this->crawlResults as $result) {
            if (isset($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    switch ($issue['type']) {
                        case 'error':
                            $errorUrls++;
                            break;
                        case 'warning':
                            $warningCount++;
                            break;
                        case 'notice':
                            $noticeCount++;
                            break;
                    }
                }
            }
        }

        // Calculate health score (Ahrefs-style: percentage of URLs without errors)
        $healthScore = $totalUrls > 0 ? round((($totalUrls - $errorUrls) / $totalUrls) * 100) : 0;

        // Update SEO check
        $this->seoCheck->update([
            'status' => 'completed',
            'health_score' => $healthScore,
            'errors_found' => $errorUrls,
            'warnings_found' => $warningCount,
            'notices_found' => $noticeCount,
            'finished_at' => now(),
            'crawl_summary' => [
                'total_urls' => $totalUrls,
                'internal_links_found' => count($this->internalLinks),
                'external_links_found' => count($this->externalLinks),
                'avg_response_time' => $this->calculateAverageResponseTime(),
            ],
        ]);
    }

    protected function calculateAverageResponseTime(): float
    {
        if (empty($this->crawlResults)) {
            return 0;
        }

        $totalTime = 0;
        $count = 0;

        foreach ($this->crawlResults as $result) {
            if (isset($result['response_time_ms'])) {
                $totalTime += $result['response_time_ms'];
                $count++;
            }
        }

        return $count > 0 ? round($totalTime / $count, 2) : 0;
    }
}
