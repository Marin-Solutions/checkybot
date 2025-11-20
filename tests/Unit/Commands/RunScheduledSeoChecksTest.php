<?php

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Services\RobotsSitemapService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('command can be executed', function () {
    $this->artisan('seo:run-scheduled')
        ->assertSuccessful()
        ->assertExitCode(0);
});

test('command finds no scheduled checks', function () {
    $this->artisan('seo:run-scheduled')
        ->expectsOutput('Checking for scheduled SEO health checks...')
        ->expectsOutput('No scheduled SEO checks are due to run.')
        ->assertSuccessful();
});

test('command dispatches job for due schedule', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->with($website->url)
        ->andReturn(['https://example.com', 'https://example.com/about']);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertPushed(SeoHealthCheckJob::class);
});

test('command creates seo check for due schedule', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com']);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    assertDatabaseHas('seo_checks', [
        'website_id' => $website->id,
        'status' => 'pending',
    ]);
});

test('command updates schedule next run time', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $originalNextRun = $schedule->next_run_at;

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com']);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    $schedule->refresh();
    expect($schedule->next_run_at)->not->toEqual($originalNextRun);
    expect($schedule->last_run_at)->not->toBeNull();
});

test('command skips schedules not due', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create();

    SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->addHour(),
    ]);

    $this->artisan('seo:run-scheduled')
        ->expectsOutput('No scheduled SEO checks are due to run.')
        ->assertSuccessful();

    Queue::assertNotPushed(SeoHealthCheckJob::class);
});

test('command skips inactive schedules', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create();

    SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => false,
        'next_run_at' => now()->subHour(),
    ]);

    $this->artisan('seo:run-scheduled')
        ->expectsOutput('No scheduled SEO checks are due to run.')
        ->assertSuccessful();

    Queue::assertNotPushed(SeoHealthCheckJob::class);
});

test('command skips schedules with no crawlable urls', function () {
    Queue::fake();
    Log::spy();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->with($website->url)
        ->andReturn([]);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertNotPushed(SeoHealthCheckJob::class);

    Log::shouldHaveReceived('warning')
        ->with("No crawlable URLs found for scheduled check: {$website->url}")
        ->once();
});

test('command processes multiple due schedules', function () {
    Queue::fake();

    $user = User::factory()->create();
    $websites = Website::factory()->count(3)->create();

    foreach ($websites as $website) {
        SeoSchedule::create([
            'website_id' => $website->id,
            'created_by' => $user->id,
            'frequency' => 'daily',
            'schedule_time' => '02:00:00',
            'is_active' => true,
            'next_run_at' => now()->subHour(),
        ]);
    }

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com']);

    $this->artisan('seo:run-scheduled')
        ->expectsOutput('Found 3 scheduled SEO checks to run.')
        ->assertSuccessful();

    Queue::assertPushed(SeoHealthCheckJob::class, 3);
});

test('command dispatches job to correct queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com']);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertPushedOn('seo-checks', SeoHealthCheckJob::class);
});

test('command creates seo check with correct data', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com', 'https://example.com/about']);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    assertDatabaseHas('seo_checks', [
        'website_id' => $website->id,
        'status' => 'pending',
        'total_crawlable_urls' => 2,
        'sitemap_used' => true,
        'robots_txt_checked' => true,
    ]);

    $seoCheck = SeoCheck::where('website_id', $website->id)->first();
    expect($seoCheck->crawl_summary['scheduled_by'])->toBe($user->id);
    expect($seoCheck->crawl_summary['schedule_id'])->toBe($schedule->id);
    expect($seoCheck->crawl_summary['is_scheduled'])->toBeTrue();
});

test('command handles exceptions gracefully', function () {
    Queue::fake();
    Log::spy();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andThrow(new \Exception('Service error'));

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Log::shouldHaveReceived('error')->once();
});

test('command displays progress bar', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com']);

    $this->artisan('seo:run-scheduled')
        ->expectsOutput('Found 1 scheduled SEO checks to run.')
        ->assertSuccessful();
});

test('command logs scheduled check start', function () {
    Queue::fake();
    Log::spy();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->andReturn(['https://example.com']);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message) use ($schedule, $website) {
            return str_contains($message, $website->url)
                && str_contains($message, (string) $schedule->id);
        })
        ->once();
});
