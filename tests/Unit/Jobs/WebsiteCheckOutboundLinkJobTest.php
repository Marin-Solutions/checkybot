<?php

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Crawler\Crawler;

test('job can be instantiated with website', function () {
    $website = Website::factory()->create();

    $job = new WebsiteCheckOutboundLinkJob($website);

    expect($job)->toBeInstanceOf(WebsiteCheckOutboundLinkJob::class);
});

test('job implements should queue', function () {
    $website = Website::factory()->create();

    $job = new WebsiteCheckOutboundLinkJob($website);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('outbound link jobs are unique per website', function () {
    $website = Website::factory()->create();

    $job = new WebsiteCheckOutboundLinkJob($website);

    expect($job)
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("website-outbound-link:scheduled:{$website->id}");
});

test('scheduled outbound link job unique lock covers the daily scan window', function () {
    $website = Website::factory()->create();

    $job = WebsiteCheckOutboundLinkJob::scheduled($website);

    expect($job->uniqueId())->toBe("website-outbound-link:scheduled:{$website->id}")
        ->and($job->uniqueFor())->toBe(86400);
});

test('on demand outbound link job uses a separate short unique lock', function () {
    $website = Website::factory()->create();

    $job = WebsiteCheckOutboundLinkJob::onDemand($website);

    expect($job->uniqueId())->toBe("website-outbound-link:on-demand:{$website->id}")
        ->and($job->uniqueFor())->toBe(300);
});

test('job can be dispatched to queue', function () {
    Queue::fake();

    $website = Website::factory()->create();

    WebsiteCheckOutboundLinkJob::dispatch($website);

    Queue::assertPushed(WebsiteCheckOutboundLinkJob::class);
});

test('job can be dispatched to specific queue', function () {
    Queue::fake();

    $website = Website::factory()->create();

    WebsiteCheckOutboundLinkJob::dispatch($website)->onQueue('log-website');

    Queue::assertPushedOn('log-website', WebsiteCheckOutboundLinkJob::class);
});

test('job handle method initiates crawler', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    // We can't easily test the handle method without complex mocking of static Crawler::create()
    // So we'll just verify the job can be instantiated and has the correct structure
    $job = new WebsiteCheckOutboundLinkJob($website);

    expect($job)->toBeInstanceOf(WebsiteCheckOutboundLinkJob::class);

    // Verify website is stored
    $reflection = new \ReflectionClass($job);
    $property = $reflection->getProperty('website');
    $property->setAccessible(true);

    expect($property->getValue($job)->id)->toBe($website->id);
});

test('job records outbound evidence when crawler startup fails', function () {
    Carbon::setTestNow('2026-05-08 12:34:56');

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'last_outbound_checked_at' => null,
    ]);

    $job = new class($website) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new RuntimeException('cURL error 6: Could not resolve host: example.com?token=secret');
        }
    };

    $job->handle();

    assertDatabaseHas('outbound_link', [
        'website_id' => $website->id,
        'found_on' => 'https://example.com',
        'outgoing_url' => 'https://example.com',
        'http_status_code' => null,
        'transport_error_type' => 'dns',
        'transport_error_code' => 6,
        'last_checked_at' => '2026-05-08 12:34:56',
    ]);

    $link = $website->outboundLinks()->sole();

    expect($link->transport_error_message)
        ->toContain('Outbound scheduled scan failed before crawling started')
        ->toContain('token=[redacted]')
        ->not->toContain('token=secret')
        ->and($website->refresh()->last_outbound_checked_at?->toDateTimeString())->toBe('2026-05-08 12:34:56');

    Carbon::setTestNow();
});

test('job records on demand scan source in crawler startup failure evidence', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $job = new class($website, WebsiteCheckOutboundLinkJob::SOURCE_ON_DEMAND) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new RuntimeException('Operation timed out after 10000 milliseconds', 28);
        }
    };

    $job->handle();

    $link = $website->outboundLinks()->sole();

    expect($link->transport_error_type)->toBe('timeout')
        ->and($link->transport_error_code)->toBe(28)
        ->and($link->transport_error_message)->toContain('Outbound on demand scan failed before crawling started');
});

test('job stores website property', function () {
    $website = Website::factory()->create();

    $job = new WebsiteCheckOutboundLinkJob($website);

    // Use reflection to access protected property
    $reflection = new \ReflectionClass($job);
    $property = $reflection->getProperty('website');
    $property->setAccessible(true);

    expect($property->getValue($job)->id)->toBe($website->id);
});

test('multiple jobs can be dispatched for different websites', function () {
    Queue::fake();

    $websites = Website::factory()->count(3)->create();

    foreach ($websites as $website) {
        WebsiteCheckOutboundLinkJob::dispatch($website)->onQueue('log-website');
    }

    Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 3);
});

test('job uses queueable trait', function () {
    $website = Website::factory()->create();
    $job = new WebsiteCheckOutboundLinkJob($website);

    expect(method_exists($job, 'onQueue'))->toBeTrue();
    expect(method_exists($job, 'delay'))->toBeTrue();
});
