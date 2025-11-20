<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RobotsSitemapService
{
    /**
     * Check if a URL is allowed to be crawled according to robots.txt
     */
    public function isUrlAllowed(string $url): bool
    {
        try {
            $baseUrl = $this->getBaseUrl($url);
            $robotsContent = $this->fetchRobotsTxt($baseUrl);

            if (empty($robotsContent)) {
                return true; // No robots.txt found, allow crawling
            }

            return $this->parseRobotsTxt($robotsContent, $url);
        } catch (\Exception $e) {
            Log::warning("Failed to check robots.txt for {$url}: ".$e->getMessage());

            // If we can't check robots.txt, allow crawling by default
            return true;
        }
    }

    /**
     * Fetch robots.txt content from a base URL
     */
    private function fetchRobotsTxt(string $baseUrl): string
    {
        try {
            $robotsUrl = rtrim($baseUrl, '/').'/robots.txt';
            $response = Http::timeout(10)->get($robotsUrl);

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch robots.txt from {$baseUrl}: ".$e->getMessage());
        }

        return '';
    }

    /**
     * Parse robots.txt content to check if URL is allowed
     */
    private function parseRobotsTxt(string $robotsContent, string $url): bool
    {
        $lines = explode("\n", $robotsContent);
        $userAgent = '*';
        $disallowedPaths = [];
        $allowedPaths = [];
        $currentUserAgent = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Check for User-agent directive
            if (stripos($line, 'User-agent:') === 0) {
                $currentUserAgent = trim(substr($line, 11));

                continue;
            }

            // Check for Disallow directive
            if (stripos($line, 'Disallow:') === 0 && $currentUserAgent === $userAgent) {
                $path = trim(substr($line, 9));
                if (! empty($path)) {
                    $disallowedPaths[] = $path;
                }
            }

            // Check for Allow directive
            if (stripos($line, 'Allow:') === 0 && $currentUserAgent === $userAgent) {
                $path = trim(substr($line, 6));
                if (! empty($path)) {
                    $allowedPaths[] = $path;
                }
            }
        }

        // Check if URL path matches any disallowed patterns
        $urlPath = parse_url($url, PHP_URL_PATH) ?? '';

        // First check if there's a specific allow rule
        foreach ($allowedPaths as $allowedPath) {
            if ($this->pathMatches($urlPath, $allowedPath)) {
                return true;
            }
        }

        // Then check disallowed patterns
        foreach ($disallowedPaths as $disallowedPath) {
            if ($this->pathMatches($urlPath, $disallowedPath)) {
                return false;
            }
        }

        return true; // Default to allowed if no specific rules match
    }

    /**
     * Check if a URL path matches a robots.txt pattern
     */
    private function pathMatches(string $urlPath, string $pattern): bool
    {
        if (empty($pattern)) {
            return false;
        }

        // Convert robots.txt pattern to regex
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = str_replace('\$', '$', $pattern);

        return preg_match('/^'.$pattern.'/', $urlPath);
    }

    /**
     * Get base URL from full URL
     */
    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return $scheme.'://'.$host;
    }

    /**
     * Get all URLs from sitemap.xml
     */
    public function getSitemapUrls(string $baseUrl): array
    {
        $sitemapUrls = [];

        try {
            // Try common sitemap locations
            $sitemapLocations = [
                $baseUrl.'/sitemap.xml',
                $baseUrl.'/sitemap_index.xml',
                $baseUrl.'/sitemaps.xml',
            ];

            foreach ($sitemapLocations as $sitemapUrl) {
                $urls = $this->parseSitemap($sitemapUrl);
                if (! empty($urls)) {
                    $sitemapUrls = array_merge($sitemapUrls, $urls);
                    break; // Use the first valid sitemap found
                }
            }

            // Also check robots.txt for sitemap declaration
            $robotsSitemapUrls = $this->getSitemapFromRobots($baseUrl);
            if (! empty($robotsSitemapUrls)) {
                $sitemapUrls = array_merge($sitemapUrls, $robotsSitemapUrls);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to get sitemap URLs for {$baseUrl}: ".$e->getMessage());
        }

        return array_unique($sitemapUrls);
    }

    /**
     * Parse a sitemap XML file and extract URLs
     */
    private function parseSitemap(string $sitemapUrl): array
    {
        try {
            $response = Http::timeout(10)->get($sitemapUrl);

            if (! $response->successful()) {
                return [];
            }

            $xml = simplexml_load_string($response->body());
            if (! $xml) {
                return [];
            }

            $urls = [];

            // Handle sitemap index
            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $sitemap) {
                    if (isset($sitemap->loc)) {
                        $nestedUrls = $this->parseSitemap((string) $sitemap->loc);
                        $urls = array_merge($urls, $nestedUrls);
                    }
                }
            }

            // Handle regular sitemap
            if (isset($xml->url)) {
                foreach ($xml->url as $url) {
                    if (isset($url->loc)) {
                        $urls[] = (string) $url->loc;
                    }
                }
            }

            return $urls;
        } catch (\Exception $e) {
            Log::warning("Failed to parse sitemap {$sitemapUrl}: ".$e->getMessage());

            return [];
        }
    }

    /**
     * Get sitemap URLs from robots.txt
     */
    private function getSitemapFromRobots(string $baseUrl): array
    {
        try {
            $robotsUrl = rtrim($baseUrl, '/').'/robots.txt';
            $response = Http::timeout(10)->get($robotsUrl);

            if (! $response->successful()) {
                return [];
            }

            $sitemapUrls = [];
            $lines = explode("\n", $response->body());

            foreach ($lines as $line) {
                $line = trim($line);
                if (stripos($line, 'Sitemap:') === 0) {
                    $sitemapUrl = trim(substr($line, 8));
                    if (! empty($sitemapUrl)) {
                        $urls = $this->parseSitemap($sitemapUrl);
                        $sitemapUrls = array_merge($sitemapUrls, $urls);
                    }
                }
            }

            return $sitemapUrls;
        } catch (\Exception $e) {
            Log::warning("Failed to get sitemap from robots.txt for {$baseUrl}: ".$e->getMessage());

            return [];
        }
    }

    /**
     * Get crawlable URLs for a website
     * Returns sitemap URLs if available, otherwise returns the base URL
     */
    public function getCrawlableUrls(string $baseUrl): array
    {
        $sitemapUrls = $this->getSitemapUrls($baseUrl);

        if (! empty($sitemapUrls)) {
            // Filter URLs that are allowed by robots.txt
            $allowedUrls = [];
            foreach ($sitemapUrls as $url) {
                if ($this->isUrlAllowed($url)) {
                    $allowedUrls[] = $url;
                }
            }

            return $allowedUrls;
        }

        // If no sitemap, start with base URL if allowed
        if ($this->isUrlAllowed($baseUrl)) {
            return [$baseUrl];
        }

        return [];
    }
}
