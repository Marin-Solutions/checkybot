<?php

use App\Services\RobotsSitemapService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new RobotsSitemapService;
});

test('allows url when no robots txt exists', function () {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('', 404),
    ]);

    $result = $this->service->isUrlAllowed('https://example.com/test');

    expect($result)->toBeTrue();
});

test('allows url when not disallowed', function () {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('
            User-agent: *
            Disallow: /admin
        ', 200),
    ]);

    $result = $this->service->isUrlAllowed('https://example.com/public');

    expect($result)->toBeTrue();
});

test('disallows url when explicitly disallowed', function () {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('
            User-agent: *
            Disallow: /admin
        ', 200),
    ]);

    $result = $this->service->isUrlAllowed('https://example.com/admin');

    expect($result)->toBeFalse();
});

test('allows url with explicit allow rule', function () {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('
            User-agent: *
            Disallow: /admin
            Allow: /admin/public
        ', 200),
    ]);

    $result = $this->service->isUrlAllowed('https://example.com/admin/public');

    expect($result)->toBeTrue();
});

test('handles wildcard patterns', function () {
    Http::fake([
        'https://example.com/robots.txt' => Http::response('
            User-agent: *
            Disallow: /*.json$
        ', 200),
    ]);

    $result = $this->service->isUrlAllowed('https://example.com/data.json');

    expect($result)->toBeFalse();
});

test('allows url on exception', function () {
    Http::fake([
        'https://example.com/robots.txt' => function () {
            throw new \Exception('Network error');
        },
    ]);

    $result = $this->service->isUrlAllowed('https://example.com/test');

    expect($result)->toBeTrue();
});

test('gets sitemap urls from sitemap xml', function () {
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

    expect($urls)->toHaveCount(2);
    expect($urls)->toContain('https://example.com/page1');
    expect($urls)->toContain('https://example.com/page2');
});

test('gets sitemap urls from sitemap index', function () {
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

    expect($urls)->toHaveCount(1);
    expect($urls)->toContain('https://example.com/post1');
});

test('gets sitemap urls from robots txt', function () {
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

    expect($urls)->toContain('https://example.com/custom-page');
});

test('returns empty array when no sitemap found', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 404),
    ]);

    $urls = $this->service->getSitemapUrls('https://example.com');

    expect($urls)->toBeEmpty();
});

test('get crawlable urls returns sitemap urls when available', function () {
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

    expect($urls)->toContain('https://example.com/page1');
});

test('get crawlable urls filters disallowed urls', function () {
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

    expect($urls)->toContain('https://example.com/public');
    expect($urls)->not->toContain('https://example.com/admin');
});

test('get crawlable urls returns base url when no sitemap', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 404),
    ]);

    $urls = $this->service->getCrawlableUrls('https://example.com');

    expect($urls)->toContain('https://example.com');
});

test('handles invalid xml in sitemap', function () {
    Http::fake([
        'https://example.com/sitemap.xml' => Http::response('Invalid XML content', 200),
    ]);

    $urls = $this->service->getSitemapUrls('https://example.com');

    expect($urls)->toBeEmpty();
});
