<?php

namespace Tests\Unit\Services;

use App\Services\RobotsSitemapService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotsSitemapServiceTest extends TestCase
{
    protected RobotsSitemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RobotsSitemapService;
    }

    public function test_allows_url_when_no_robots_txt_exists(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $result = $this->service->isUrlAllowed('https://example.com/test');

        $this->assertTrue($result);
    }

    public function test_allows_url_when_not_disallowed(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Disallow: /admin
            ', 200),
        ]);

        $result = $this->service->isUrlAllowed('https://example.com/public');

        $this->assertTrue($result);
    }

    public function test_disallows_url_when_explicitly_disallowed(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Disallow: /admin
            ', 200),
        ]);

        $result = $this->service->isUrlAllowed('https://example.com/admin');

        $this->assertFalse($result);
    }

    public function test_allows_url_with_explicit_allow_rule(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Disallow: /admin
                Allow: /admin/public
            ', 200),
        ]);

        $result = $this->service->isUrlAllowed('https://example.com/admin/public');

        $this->assertTrue($result);
    }

    public function test_handles_wildcard_patterns(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Disallow: /*.json$
            ', 200),
        ]);

        $result = $this->service->isUrlAllowed('https://example.com/data.json');

        $this->assertFalse($result);
    }

    public function test_allows_url_on_exception(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => function () {
                throw new \Exception('Network error');
            },
        ]);

        $result = $this->service->isUrlAllowed('https://example.com/test');

        $this->assertTrue($result);
    }

    public function test_gets_sitemap_urls_from_sitemap_xml(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url>
                        <loc>https://example.com/page1</loc>
                    </url>
                    <url>
                        <loc>https://example.com/page2</loc>
                    </url>
                </urlset>', 200),
        ]);

        $urls = $this->service->getSitemapUrls('https://example.com');

        $this->assertCount(2, $urls);
        $this->assertContains('https://example.com/page1', $urls);
        $this->assertContains('https://example.com/page2', $urls);
    }

    public function test_gets_sitemap_urls_from_sitemap_index(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <sitemap>
                        <loc>https://example.com/sitemap-posts.xml</loc>
                    </sitemap>
                </sitemapindex>', 200),
            'https://example.com/sitemap-posts.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url>
                        <loc>https://example.com/post1</loc>
                    </url>
                </urlset>', 200),
        ]);

        $urls = $this->service->getSitemapUrls('https://example.com');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/post1', $urls);
    }

    public function test_gets_sitemap_urls_from_robots_txt(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com/sitemap_index.xml' => Http::response('', 404),
            'https://example.com/sitemaps.xml' => Http::response('', 404),
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Sitemap: https://example.com/custom-sitemap.xml
            ', 200),
            'https://example.com/custom-sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url>
                        <loc>https://example.com/custom-page</loc>
                    </url>
                </urlset>', 200),
        ]);

        $urls = $this->service->getSitemapUrls('https://example.com');

        $this->assertContains('https://example.com/custom-page', $urls);
    }

    public function test_returns_empty_array_when_no_sitemap_found(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('', 404),
        ]);

        $urls = $this->service->getSitemapUrls('https://example.com');

        $this->assertEmpty($urls);
    }

    public function test_get_crawlable_urls_returns_sitemap_urls_when_available(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url>
                        <loc>https://example.com/page1</loc>
                    </url>
                </urlset>', 200),
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Allow: /
            ', 200),
        ]);

        $urls = $this->service->getCrawlableUrls('https://example.com');

        $this->assertContains('https://example.com/page1', $urls);
    }

    public function test_get_crawlable_urls_filters_disallowed_urls(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url>
                        <loc>https://example.com/public</loc>
                    </url>
                    <url>
                        <loc>https://example.com/admin</loc>
                    </url>
                </urlset>', 200),
            'https://example.com/robots.txt' => Http::response('
                User-agent: *
                Disallow: /admin
            ', 200),
        ]);

        $urls = $this->service->getCrawlableUrls('https://example.com');

        $this->assertContains('https://example.com/public', $urls);
        $this->assertNotContains('https://example.com/admin', $urls);
    }

    public function test_get_crawlable_urls_returns_base_url_when_no_sitemap(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('', 404),
        ]);

        $urls = $this->service->getCrawlableUrls('https://example.com');

        $this->assertContains('https://example.com', $urls);
    }

    public function test_handles_invalid_xml_in_sitemap(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('Invalid XML content', 200),
        ]);

        $urls = $this->service->getSitemapUrls('https://example.com');

        $this->assertEmpty($urls);
    }
}
