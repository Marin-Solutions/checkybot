<?php

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Enums\UptimeTransportErrorType;
use App\Mail\EmailErrorOutgoingUrl;
use App\Models\OutboundLink;
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

test('finished crawling refreshes current outbound links and removes stale rows', function () {
    $currentLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 404,
        'last_checked_at' => now()->subDay(),
    ]);

    OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/old-source',
        'outgoing_url' => 'https://stale.example/page',
        'http_status_code' => 500,
        'last_checked_at' => now()->subDay(),
    ]);

    $this->crawler->crawled(
        new Uri('https://external.com/page'),
        new Response(200),
        new Uri('https://example.com/source'),
        'Recovered Link',
    );

    $this->crawler->finishedCrawling();

    expect(OutboundLink::query()->where('website_id', $this->website->id)->count())->toBe(1);

    assertDatabaseHas('outbound_link', [
        'id' => $currentLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 200,
    ]);

    assertDatabaseMissing('outbound_link', [
        'website_id' => $this->website->id,
        'outgoing_url' => 'https://stale.example/page',
    ]);
});

test('finished crawling collapses duplicate rows for the same outbound link key', function () {
    OutboundLink::factory()->count(2)->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 404,
        'last_checked_at' => now()->subDay(),
    ]);

    $this->crawler->crawled(
        new Uri('https://external.com/page'),
        new Response(200),
        new Uri('https://example.com/source'),
        'Duplicate Link',
    );

    $this->crawler->finishedCrawling();

    expect(OutboundLink::query()
        ->where('website_id', $this->website->id)
        ->where('found_on', 'https://example.com/source')
        ->where('outgoing_url', 'https://external.com/page')
        ->count())->toBe(1);

    assertDatabaseHas('outbound_link', [
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 200,
    ]);
});

test('finished crawling removes existing outbound links when successful crawl finds no outbound links', function () {
    OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
    ]);

    $this->crawler->crawled(
        new Uri('https://example.com/source'),
        new Response(200),
        null,
        'Home',
    );

    $this->crawler->finishedCrawling();

    expect(OutboundLink::query()->where('website_id', $this->website->id)->count())->toBe(0);
});

test('finished crawling preserves existing outbound links when crawl has no successful pages', function () {
    $existingLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
    ]);

    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'id' => $existingLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
    ]);
});

test('failed crawl without a source page preserves existing outbound links', function () {
    $existingLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
    ]);

    $url = new Uri('https://example.com');
    $request = new Request('GET', $url);
    $exception = new RequestException('Connection timed out', $request);

    $this->crawler->crawlFailed($url, $exception, null, null);
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'id' => $existingLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
    ]);
});

test('failed internal crawl preserves stale outbound links from unobserved pages', function () {
    $existingLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/blog',
        'outgoing_url' => 'https://external.com/page',
    ]);

    $this->crawler->crawled(
        new Uri('https://example.com'),
        new Response(200),
        null,
        'Home',
    );

    $failedUrl = new Uri('https://example.com/blog');
    $request = new Request('GET', $failedUrl);
    $exception = new RequestException('cURL error 28: Operation timed out', $request);

    $this->crawler->crawlFailed($failedUrl, $exception, new Uri('https://example.com'), 'Blog');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'id' => $existingLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/blog',
        'outgoing_url' => 'https://external.com/page',
    ]);
});

test('failed internal crawl still refreshes observed outbound links', function () {
    $observedLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/observed',
        'http_status_code' => 404,
    ]);

    $staleLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/blog',
        'outgoing_url' => 'https://external.com/stale',
    ]);

    $this->crawler->crawled(
        new Uri('https://external.com/observed'),
        new Response(200),
        new Uri('https://example.com/source'),
        'Observed Link',
    );

    $failedUrl = new Uri('https://example.com/blog');
    $request = new Request('GET', $failedUrl);
    $exception = new RequestException('cURL error 28: Operation timed out', $request);

    $this->crawler->crawlFailed($failedUrl, $exception, new Uri('https://example.com'), 'Blog');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'id' => $observedLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/observed',
        'http_status_code' => 200,
    ]);

    assertDatabaseHas('outbound_link', [
        'id' => $staleLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/blog',
        'outgoing_url' => 'https://external.com/stale',
    ]);
});

test('failed outbound crawl keeps existing link in the latest scan', function () {
    $existingLink = OutboundLink::factory()->create([
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 200,
        'last_checked_at' => now()->subDay(),
    ]);

    $url = new Uri('https://external.com/page');
    $foundOnUrl = new Uri('https://example.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException('Connection timed out', $request);

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'id' => $existingLink->id,
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => null,
    ]);

    expect(OutboundLink::query()->where('website_id', $this->website->id)->count())->toBe(1);
});

test('failed outbound crawl records response status when available', function () {
    $url = new Uri('https://external.com/not-found');
    $foundOnUrl = new Uri('https://example.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException('Not found', $request, new Response(404));

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'website_id' => $this->website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/not-found',
        'http_status_code' => 404,
    ]);
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

test('crawl failed stores outbound link evidence with transport error details', function () {
    $url = new Uri('https://external.com/page');
    $foundOnUrl = new Uri('https://example.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException('cURL error 6: Could not resolve host: external.com', $request);

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'website_id' => $this->website->id,
        'outgoing_url' => 'https://external.com/page',
        'found_on' => 'https://example.com/source',
        'http_status_code' => null,
        'transport_error_type' => UptimeTransportErrorType::Dns->value,
        'transport_error_code' => 6,
    ]);
});

test('crawl failed redacts sensitive transport error details before storing evidence', function () {
    $url = new Uri('https://user:secret@external.com/private?token=secret-token&debug=true');
    $foundOnUrl = new Uri('https://example.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException(
        'Could not resolve https://user:secret@external.com/private?token=secret-token&debug=true with Bearer secret-bearer-token',
        $request,
    );

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseHas('outbound_link', [
        'website_id' => $this->website->id,
        'outgoing_url' => 'https://user:secret@external.com/private?token=secret-token&debug=true',
        'found_on' => 'https://example.com/source',
        'http_status_code' => null,
        'transport_error_type' => UptimeTransportErrorType::Dns->value,
        'transport_error_message' => 'Could not resolve https://external.com/[redacted-url] with Bearer [redacted]',
    ]);
});

test('crawl failed ignores internal link failures', function () {
    $url = new Uri('https://example.com/internal-page');
    $foundOnUrl = new Uri('https://example.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException('cURL error 28: Operation timed out', $request);

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Internal Link');
    $this->crawler->finishedCrawling();

    assertDatabaseMissing('outbound_link', [
        'outgoing_url' => 'https://example.com/internal-page',
    ]);
});

test('crawl failed ignores same-domain source failures', function () {
    $url = new Uri('https://external.com/page');
    $foundOnUrl = new Uri('https://external.com/source');
    $request = new Request('GET', $url);
    $exception = new RequestException('Error', $request);

    $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');
    $this->crawler->finishedCrawling();

    assertDatabaseMissing('outbound_link', [
        'outgoing_url' => 'https://external.com/page',
    ]);
});
