<?php

namespace Tests\Unit\Crawlers;

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Mail\EmailErrorOutgoingUrl;
use App\Models\User;
use App\Models\Website;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WebsiteOutboundLinkCrawlerTest extends TestCase
{
    protected Website $website;

    protected User $user;

    protected WebsiteOutboundLinkCrawler $crawler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsUser();
        $this->website = Website::factory()->create([
            'url' => 'https://example.com',
            'created_by' => $this->user->id,
        ]);
        $this->crawler = new WebsiteOutboundLinkCrawler($this->website);
    }

    public function test_crawled_stores_outbound_link(): void
    {
        $url = new Uri('https://external.com/page');
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(200);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Link Text');
        $this->crawler->finishedCrawling();

        $this->assertDatabaseHas('outbound_link', [
            'website_id' => $this->website->id,
            'outgoing_url' => 'https://external.com/page',
            'found_on' => 'https://example.com/source',
            'http_status_code' => 200,
        ]);
    }

    public function test_crawled_ignores_internal_links(): void
    {
        $url = new Uri('https://example.com/internal-page');
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(200);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Link Text');
        $this->crawler->finishedCrawling();

        $this->assertDatabaseMissing('outbound_link', [
            'outgoing_url' => 'https://example.com/internal-page',
        ]);
    }

    public function test_crawled_handles_null_found_on_url(): void
    {
        $url = new Uri('https://external.com/page');
        $response = new Response(200);

        $this->crawler->crawled($url, $response, null, 'Link Text');
        $this->crawler->finishedCrawling();

        $this->assertDatabaseMissing('outbound_link', [
            'outgoing_url' => 'https://external.com/page',
        ]);
    }

    public function test_finished_crawling_updates_website_timestamp(): void
    {
        $originalTimestamp = $this->website->last_outbound_checked_at;

        $this->crawler->finishedCrawling();

        $this->website->refresh();
        $this->assertNotEquals($originalTimestamp, $this->website->last_outbound_checked_at);
    }

    public function test_sends_email_notification_for_404_errors(): void
    {
        Mail::fake();

        $url = new Uri('https://external.com/not-found');
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(404);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Broken Link');
        $this->crawler->finishedCrawling();

        Mail::assertSent(EmailErrorOutgoingUrl::class, function ($mail) {
            return $mail->hasTo($this->user->email);
        });
    }

    public function test_sends_email_notification_for_500_errors(): void
    {
        Mail::fake();

        $url = new Uri('https://external.com/error');
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(500);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Server Error Link');
        $this->crawler->finishedCrawling();

        Mail::assertSent(EmailErrorOutgoingUrl::class, function ($mail) {
            return $mail->hasTo($this->user->email);
        });
    }

    public function test_does_not_send_email_for_200_response(): void
    {
        Mail::fake();

        $url = new Uri('https://external.com/working');
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(200);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Working Link');
        $this->crawler->finishedCrawling();

        Mail::assertNotSent(EmailErrorOutgoingUrl::class);
    }

    public function test_stores_multiple_outbound_links(): void
    {
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
            $this->assertDatabaseHas('outbound_link', [
                'website_id' => $this->website->id,
                'outgoing_url' => $urlString,
            ]);
        }
    }

    public function test_handles_subdomain_as_external_link(): void
    {
        $url = new Uri('https://subdomain.otherdomain.com/page');
        $foundOnUrl = new Uri('https://example.com/source');
        $response = new Response(200);

        $this->crawler->crawled($url, $response, $foundOnUrl, 'Subdomain Link');
        $this->crawler->finishedCrawling();

        $this->assertDatabaseHas('outbound_link', [
            'outgoing_url' => 'https://subdomain.otherdomain.com/page',
        ]);
    }

    public function test_stores_correct_http_status_codes(): void
    {
        $statusCodes = [200, 201, 301, 302, 404, 500];

        foreach ($statusCodes as $code) {
            $url = new Uri("https://external.com/page{$code}");
            $foundOnUrl = new Uri('https://example.com/source');
            $response = new Response($code);

            $this->crawler->crawled($url, $response, $foundOnUrl, 'Link');
        }

        $this->crawler->finishedCrawling();

        foreach ($statusCodes as $code) {
            $this->assertDatabaseHas('outbound_link', [
                'http_status_code' => $code,
            ]);
        }
    }

    public function test_will_crawl_does_nothing(): void
    {
        $url = new Uri('https://external.com/page');

        $this->crawler->willCrawl($url, 'Link Text');

        $this->assertTrue(true);
    }

    public function test_crawl_failed_does_nothing(): void
    {
        $url = new Uri('https://external.com/page');
        $foundOnUrl = new Uri('https://example.com/source');
        $request = new Request('GET', $url);
        $exception = new RequestException('Error', $request);

        $this->crawler->crawlFailed($url, $exception, $foundOnUrl, 'Link Text');

        $this->assertTrue(true);
    }
}
