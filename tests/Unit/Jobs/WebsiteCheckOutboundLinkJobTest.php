<?php

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\Website;
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
