<?php

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\OutboundLink;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    $job = new WebsiteCheckOutboundLinkJob($website);

    expect($job)->toBeInstanceOf(WebsiteCheckOutboundLinkJob::class);

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
        'outbound_scan_queued_at' => now()->subMinutes(5),
    ]);

    $job = new class($website) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('cURL error 6: Could not resolve host: example.com?token=secret');
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
        ->and($website->refresh()->last_outbound_checked_at?->toDateTimeString())->toBe('2026-05-08 12:34:56')
        ->and($website->outbound_scan_queued_at)->toBeNull();

    Carbon::setTestNow();
});

test('job notifies website when crawler startup failure is newly broken outbound evidence', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $this->mock(HealthEventNotificationService::class, function ($mock) use ($website): void {
        $mock->shouldReceive('notifyWebsite')
            ->once()
            ->withArgs(function (Website $notifiedWebsite, string $event, string $status, string $summary) use ($website): bool {
                return $notifiedWebsite->is($website)
                    && $event === 'outbound_link_broken'
                    && $status === 'danger'
                    && str_contains($summary, 'Outbound link check failed to start.')
                    && str_contains($summary, 'https://example.com could not be reached (DNS failure) before crawling began.');
            })
            ->andReturn(true);
    });

    $job = new class($website) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('cURL error 6: Could not resolve host: example.com', 6);
        }
    };

    $job->handle();
});

test('job does not notify website when crawler startup failure updates existing broken outbound evidence', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    OutboundLink::factory()->create([
        'website_id' => $website->id,
        'found_on' => 'https://example.com',
        'outgoing_url' => 'https://example.com',
        'http_status_code' => null,
        'transport_error_type' => 'timeout',
    ]);

    $this->mock(HealthEventNotificationService::class, function ($mock): void {
        $mock->shouldNotReceive('notifyWebsite');
    });

    $job = new class($website) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('Operation timed out after 10000 milliseconds', 28);
        }
    };

    $job->handle();
});

test('job records on demand scan source in crawler startup failure evidence', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $job = new class($website, WebsiteCheckOutboundLinkJob::SOURCE_ON_DEMAND) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('Operation timed out after 10000 milliseconds', 28);
        }
    };

    $job->handle();

    $link = $website->outboundLinks()->sole();

    expect($link->transport_error_type)->toBe('timeout')
        ->and($link->transport_error_code)->toBe(28)
        ->and($link->transport_error_message)->toContain('Outbound on demand scan failed before crawling started');
});

test('job records startup failure evidence with fallback source label', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $job = new class($website, 'unexpected-source') extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('Crawler bootstrap failed');
        }
    };

    $job->handle();

    $link = $website->outboundLinks()->sole();

    expect($link->transport_error_message)->toContain('Outbound unknown source scan failed before crawling started');
});

test('job records startup failure evidence when website base url cannot be normalized', function () {
    Log::spy();

    $website = Website::factory()->create([
        'url' => 'not-a-valid-url',
    ]);

    $job = new class($website) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('Crawler bootstrap failed');
        }
    };

    $job->handle();

    assertDatabaseHas('outbound_link', [
        'website_id' => $website->id,
        'found_on' => 'not-a-valid-url',
        'outgoing_url' => 'not-a-valid-url',
        'transport_error_type' => 'unknown',
    ]);

    Log::shouldHaveReceived('error')
        ->with('Outbound link check failed for website not-a-valid-url: Crawler bootstrap failed')
        ->once();
});

test('job records unknown transport error when crawler startup failure cannot be classified', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $job = new class($website) extends WebsiteCheckOutboundLinkJob
    {
        public function createCrawler(): Crawler
        {
            throw new \RuntimeException('Crawler bootstrap failed');
        }
    };

    $job->handle();

    $link = $website->outboundLinks()->sole();

    expect($link->transport_error_type)->toBe('unknown')
        ->and($link->transport_error_code)->toBeNull()
        ->and($link->transport_error_message)->toContain('Crawler bootstrap failed');
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
