<?php

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Services\RobotsSitemapService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('command can be executed', function () {
    $this->artisan('seo:run-scheduled')
        ->assertSuccessful()
        ->assertExitCode(0);
});

test('heavy scheduled commands use overlap protection', function () {
    $events = collect(app(Schedule::class)->events());

    $findEvent = fn (string $command) => $events->first(
        fn ($event) => str_contains((string) $event->command, $command)
    );

    $monitorCheckApisEvent = $findEvent('monitor:check-apis');
    $runScheduledEvent = $findEvent('seo:run-scheduled');
    $markStalePackagesEvent = $findEvent('app:mark-stale-package-checks');
    $projectComponentsEvent = $findEvent('project-components:check-stale');

    expect($monitorCheckApisEvent)->not->toBeNull();
    expect($runScheduledEvent)->not->toBeNull();
    expect($markStalePackagesEvent)->not->toBeNull();
    expect($projectComponentsEvent)->not->toBeNull();

    expect($monitorCheckApisEvent->withoutOverlapping)->toBeTrue();
    expect($runScheduledEvent->withoutOverlapping)->toBeTrue();
    expect($markStalePackagesEvent->withoutOverlapping)->toBeTrue();
    expect($projectComponentsEvent->withoutOverlapping)->toBeTrue();
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

test('command skips due schedules when a website already has a pending or running check', function () {
    Queue::fake();

    $user = User::factory()->create();
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    SeoCheck::factory()->running()->create([
        'website_id' => $website->id,
    ]);

    $schedule = SeoSchedule::create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);
    $originalNextRun = $schedule->next_run_at->copy();

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldNotReceive('getCrawlableUrls');

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    expect(SeoCheck::where('website_id', $website->id)->count())->toBe(1);

    Queue::assertNotPushed(SeoHealthCheckJob::class);

    $schedule->refresh();
    expect($schedule->last_run_at)->toBeNull();
    expect($schedule->next_run_at->equalTo($originalNextRun))->toBeTrue();
});
