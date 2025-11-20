<?php

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Mail\EmailErrorOutgoingUrl;
use App\Models\Website;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = $this->actingAsUser();
    $this->website = Website::factory()->create([
        'url' => 'https://example.com',
        'created_by' => $this->user->id,
    ]);
    $this->crawler = new WebsiteOutboundLinkCrawler($this->website);
});

test('crawled stores outbound link', function () {
    $url = new Uri('https://external.com/page');
    $foundOnUrl = new Uri('https://example.com/source');
    $response = new Response(200);

    $this->crawler->crawled($url, $response, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'website_id' => $this->website->id,
        'outgoing_url' => 'https://external.com/page',
        'found_on' => 'https://example.com/source',
        'http_status_code' => 200,
    ]);
});

test('crawled ignores internal links', function () {
    $url = new Uri('https://example.com/internal-page');
    $foundOnUrl = new Uri('https://example.com/source');
    $response = new Response(200);

    $this->crawler->crawled($url, $response, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseMissing('outbound_link', [
        'outgoing_url' => 'https://example.com/internal-page',
    ]);
});

test('crawled handles null found on url', function () {
    $url = new Uri('https://external.com/page');
    $response = new Response(200);

    $this->crawler->crawled($url, $response, null, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseMissing('outbound_link', [
        'outgoing_url' => 'https://external.com/page',
    ]);
});

test('finished crawling updates website timestamp', function () {
    $originalTimestamp = $this->website->last_outbound_checked_at;

    $this->crawler->finishedCrawling();

    $this->website->refresh();
    expect($this->website->last_outbound_checked_at)->not->toBe($originalTimestamp);
});

test('sends email notification for 404 errors', function () {
    Mail::fake();

    $url = new Uri('https://external.com/not-found');
    $foundOnUrl = new Uri('https://example.com/source');
    $response = new Response(404);

    $this->crawler->crawled($url, $response, $foundOnUrl, 'Broken Link');
    $this->crawler->finishedCrawling();

    Mail::assertSent(EmailErrorOutgoingUrl::class, function ($mail) {
        return $mail->hasTo($this->user->email);
    });
});

test('sends email notification for 500 errors', function () {
    Mail::fake();

    $url = new Uri('https://external.com/error');
    $foundOnUrl = new Uri('https://example.com/source');
    $response = new Response(500);

    $this->crawler->crawled($url, $response, $foundOnUrl, 'Server Error Link');
    $this->crawler->finishedCrawling();

    Mail::assertSent(EmailErrorOutgoingUrl::class, function ($mail) {
        return $mail->hasTo($this->user->email);
    });
});

test('does not send email for 200 response', function () {
    Mail::fake();

    $url = new Uri('https://external.com/working');
    $foundOnUrl = new Uri('https://example.com/source');
    $response = new Response(200);

    $this->crawler->crawled($url, $response, $foundOnUrl, 'Working Link');
    $this->crawler->finishedCrawling();

    Mail::assertNotSent(EmailErrorOutgoingUrl::class);
});

test('stores multiple outbound links', function () {
    $urls = [
        'https://external1.com/page1',
        'https://external2.com/page2',
        'https://external3.com/page3',
    ];

    foreach ($urls as $urlString) {
        $url = new Uri($urlString);
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(200);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Link');
    }

    $this->crawler->finishedCrawling();

    foreach ($urls as $urlString) {
        assertDatabaseHas('outbound_link', [
            'website_id' => $this->website->id,
            'outgoing_url' => $urlString,
        ]);
    }
});

test('handles subdomain as external link', function () {
    $url = new Uri('https://subdomain.otherdomain.com/page');
    $foundOnUrl = new Uri('https://example.com/source');
    $response = new Response(200);

    $this->crawler->crawled($url, $response, $foundOnUrl, 'Subdomain Link');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'outgoing_url' => 'https://subdomain.otherdomain.com/page',
    ]);
});

test('stores correct http status codes', function () {
    $statusCodes = [200, 201, 301, 302, 404, 500];

    foreach ($statusCodes as $code) {
        $url = new Uri("https://external.com/page{$code}");
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response($code);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Link');
    }

    $this->crawler->finishedCrawling();

    foreach ($statusCodes as $code) {
        assertDatabaseHas('outbound_link', [
            'http_status_code' => $code,
        ]);
    }
});

test('will crawl does nothing', function () {
    $url = new Uri('https://external.com/page');

    $this->crawler->willCrawl($url, 'Link Text');

    expect(true)->toBeTrue();
});

test('crawl failed does nothing', function () {
    $url = new Uri('https://external.com/page');
    $foundOnUrl = new Uri('https://example.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException('Error', $request);

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');

    expect(true)->toBeTrue();
});
