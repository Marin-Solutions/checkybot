<?php

namespace App\Crawlers;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class SimpleSeoCrawler extends CrawlObserver
{
    protected SeoCheck $seoCheck;
    protected int $crawledCount = 0;
    protected int $maxUrls = 150;

    public function __construct(SeoCheck $seoCheck)
    {
        $this->seoCheck = $seoCheck;
    }

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        // Skip if we've reached the maximum URLs limit
        if ($this->crawledCount >= $this->maxUrls) {
            return;
        }

        $this->crawledCount++;
        $urlString = (string) $url;
        $statusCode = $response->getStatusCode();

        // Read response body immediately - simple approach like your example
        $body = (string) $response->getBody();
        $bodySize = strlen($body);

        Log::info("Simple Crawler: Crawled {$urlString} [{$statusCode}] size={$bodySize} bytes");

        // Store crawl result directly - simple approach like your example
        SeoCrawlResult::create([
            'seo_check_id' => $this->seoCheck->id,
            'url' => $urlString,
            'status_code' => $statusCode,
            'html_content' => $body,
            'html_size_bytes' => $bodySize,
            'response_time_ms' => 0, // We'll calculate this if needed
            'meta_title' => $this->extractSimpleTitle($body),
            'meta_description' => $this->extractSimpleMetaDescription($body),
            'h1_tag' => $this->extractSimpleH1($body),
            'internal_links' => [],
            'external_links' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update SEO check progress
        $this->seoCheck->update([
            'total_urls_crawled' => $this->crawledCount,
            'updated_at' => now(),
        ]);

        // Broadcast progress update
        // broadcast(new \App\Events\CrawlProgressUpdated($this->seoCheck));
    }

    public function crawlFailed(UriInterface $url, \GuzzleHttp\Exception\RequestException $requestException, ?UriInterface $foundOnUrl = null, ?string $linkText = null): void
    {
        Log::warning("Simple Crawler: Failed to crawl {$url}: " . $requestException->getMessage());
    }

    public function finishedCrawling(): void
    {
        // Mark SEO check as completed
        $this->seoCheck->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        // Broadcast completion
        // broadcast(new \App\Events\CrawlCompleted($this->seoCheck));

        Log::info("Simple Crawler: Finished crawling {$this->crawledCount} URLs for SEO check {$this->seoCheck->id}");
    }

    /**
     * Simple title extraction using regex - no DOM parsing
     */
    private function extractSimpleTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        return null;
    }

    /**
     * Simple meta description extraction using regex
     */
    private function extractSimpleMetaDescription(string $html): ?string
    {
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Simple H1 extraction using regex
     */
    private function extractSimpleH1(string $html): ?string
    {
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        return null;
    }
}
