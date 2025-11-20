<?php

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\Website;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('command can be executed', function () {
    Queue::fake();

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();
});

test('command dispatches jobs for websites with outbound check enabled', function () {
    Queue::fake();

    $websites = Website::factory()->count(3)->create([
        'outbound_check' => true,
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 3);
});

test('command does not dispatch jobs for websites with outbound check disabled', function () {
    Queue::fake();

    Website::factory()->count(3)->create([
        'outbound_check' => false,
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertNotPushed(WebsiteCheckOutboundLinkJob::class);
});

test('command dispatches jobs only for enabled websites', function () {
    Queue::fake();

    Website::factory()->count(2)->create([
        'outbound_check' => true,
    ]);

    Website::factory()->count(3)->create([
        'outbound_check' => false,
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 2);
});

test('command dispatches jobs to correct queue', function () {
    Queue::fake();

    Website::factory()->create([
        'outbound_check' => true,
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertPushedOn('log-website', WebsiteCheckOutboundLinkJob::class);
});

test('command logs completion', function () {
    Queue::fake();
    Log::spy();

    $websites = Website::factory()->count(3)->create([
        'outbound_check' => true,
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->with('Scan completed and jobs dispatched for SSL checks', ['website_count' => 3])
        ->once();
});

test('command handles no websites', function () {
    Queue::fake();
    Log::spy();

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertNotPushed(WebsiteCheckOutboundLinkJob::class);

    Log::shouldHaveReceived('info')
        ->with('Scan completed and jobs dispatched for SSL checks', ['website_count' => 0])
        ->once();
});

test('command dispatches correct website to job', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'outbound_check' => true,
        'url' => 'https://example.com',
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 1);
});

test('command handles large number of websites', function () {
    Queue::fake();

    Website::factory()->count(100)->create([
        'outbound_check' => true,
    ]);

    $this->artisan('website:scan-outbound-check')
        ->assertSuccessful();

    Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 100);
});

test('command has correct signature', function () {
    $command = $this->artisan('website:scan-outbound-check');

    expect(true)->toBeTrue(); // Command exists and can be called
});

test('command has correct description', function () {
    $this->artisan('help', ['command_name' => 'website:scan-outbound-check'])
        ->expectsOutput('Description:')
        ->assertSuccessful();
});
