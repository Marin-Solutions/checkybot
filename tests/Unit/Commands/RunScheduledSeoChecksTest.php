<?php

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Services\RobotsSitemapService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
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

    expect($monitorCheckApisEvent)->not->toBeNull();
    expect($runScheduledEvent)->not->toBeNull();

    expect($monitorCheckApisEvent->withoutOverlapping)->toBeTrue();
    expect($runScheduledEvent->withoutOverlapping)->toBeTrue();
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

test('command records failed check and advances schedule when no crawlable urls found', function () {
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

    $originalNextRun = $schedule->next_run_at->copy();

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertNotPushed(SeoHealthCheckJob::class);

    $schedule->refresh();

    expect($schedule->last_run_at)->not->toBeNull();
    expect($schedule->next_run_at->equalTo($originalNextRun))->toBeFalse();
    expect($schedule->next_run_at->isFuture())->toBeTrue();

    $seoCheck = SeoCheck::where('website_id', $website->id)->first();

    expect($seoCheck)->not->toBeNull()
        ->and($seoCheck->status)->toBe('failed')
        ->and($seoCheck->total_urls_crawled)->toBe(0)
        ->and($seoCheck->total_crawlable_urls)->toBe(0)
        ->and($seoCheck->robots_txt_checked)->toBeTrue()
        ->and($seoCheck->started_at)->not->toBeNull()
        ->and($seoCheck->finished_at)->not->toBeNull()
        ->and($seoCheck->finished_at->equalTo($seoCheck->started_at))->toBeTrue()
        ->and($seoCheck->failure_summary)->toBe('No crawlable URLs were found. The sitemap may be empty, unavailable, or blocked by robots.txt.')
        ->and($seoCheck->failure_context)->toMatchArray([
            'failure_reason' => 'no_crawlable_urls',
            'website_url' => $website->url,
            'schedule_id' => $schedule->id,
            'scheduled_by' => $user->id,
        ])
        ->and($seoCheck->failure_context['checked_at'])->not->toBeEmpty()
        ->and($seoCheck->crawl_summary['scheduled_by'])->toBe($user->id)
        ->and($seoCheck->crawl_summary['schedule_id'])->toBe($schedule->id)
        ->and($seoCheck->crawl_summary['is_scheduled'])->toBeTrue()
        ->and($seoCheck->crawl_summary['failure_reason'])->toBe('no_crawlable_urls')
        ->and($seoCheck->crawl_summary['summary'])->toContain('No crawlable URLs were found');

    Log::shouldHaveReceived('warning')
        ->withArgs(function ($message) use ($schedule, $website) {
            return str_contains($message, "No crawlable URLs found for scheduled check: {$website->url}")
                && str_contains($message, (string) $schedule->next_run_at);
        })
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
    $mockService->shouldReceive('getCrawlableUrls')
        ->with($website->url)
        ->andThrow(new \RuntimeException('Service error'));

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertNotPushed(SeoHealthCheckJob::class);

    $schedule->refresh();

    expect($schedule->last_run_at)->not->toBeNull();
    expect($schedule->next_run_at->equalTo($originalNextRun))->toBeFalse();
    expect($schedule->next_run_at->isFuture())->toBeTrue();

    $seoCheck = SeoCheck::where('website_id', $website->id)->first();

    expect($seoCheck)->not->toBeNull()
        ->and($seoCheck->status)->toBe('failed')
        ->and($seoCheck->total_urls_crawled)->toBe(0)
        ->and($seoCheck->total_crawlable_urls)->toBe(0)
        ->and($seoCheck->robots_txt_checked)->toBeFalse()
        ->and($seoCheck->started_at)->not->toBeNull()
        ->and($seoCheck->finished_at)->not->toBeNull()
        ->and($seoCheck->finished_at->equalTo($seoCheck->started_at))->toBeTrue()
        ->and($seoCheck->failure_summary)->toBe('Scheduled SEO check could not start: Service error')
        ->and($seoCheck->failure_context)->toMatchArray([
            'failure_reason' => 'scheduled_startup_failed',
            'website_url' => $website->url,
            'schedule_id' => $schedule->id,
            'scheduled_by' => $user->id,
            'exception_class' => RuntimeException::class,
            'exception_message' => 'Service error',
        ])
        ->and($seoCheck->failure_context['checked_at'])->not->toBeEmpty()
        ->and($seoCheck->crawl_summary['scheduled_by'])->toBe($user->id)
        ->and($seoCheck->crawl_summary['schedule_id'])->toBe($schedule->id)
        ->and($seoCheck->crawl_summary['is_scheduled'])->toBeTrue()
        ->and($seoCheck->crawl_summary['failure_reason'])->toBe('scheduled_startup_failed')
        ->and($seoCheck->crawl_summary['summary'])->toBe('Scheduled SEO check could not start: Service error');

    Log::shouldHaveReceived('error')
        ->withArgs(function ($message) use ($schedule, $website) {
            return str_contains($message, "Failed to start scheduled SEO check for website {$website->url}: Service error")
                && str_contains($message, (string) $schedule->next_run_at);
        })
        ->once();
});

test('command exits successfully when due schedule query fails', function () {
    Log::spy();

    $originalResolver = Model::getConnectionResolver();
    $resolver = Mockery::mock(\Illuminate\Database\ConnectionResolverInterface::class);
    $resolver->shouldReceive('connection')
        ->andThrow(new RuntimeException('database unavailable'));

    Model::setConnectionResolver($resolver);

    try {
        $this->artisan('seo:run-scheduled')
            ->expectsOutput('Checking for scheduled SEO health checks...')
            ->expectsOutput('Scheduled SEO checks could not load due schedules: database unavailable')
            ->assertSuccessful();
    } finally {
        Model::setConnectionResolver($originalResolver);
    }

    Log::shouldHaveReceived('error')
        ->withArgs(function ($message, $context = []) {
            return $message === 'Scheduled SEO checks could not load due schedules: database unavailable'
                && ($context['exception_class'] ?? null) === RuntimeException::class;
        })
        ->once();
});

test('command continues processing due schedules after one startup failure', function () {
    Queue::fake();

    $user = User::factory()->create();
    $failingWebsite = Website::factory()->create([
        'url' => 'https://failing.example.com',
    ]);
    $healthyWebsite = Website::factory()->create([
        'url' => 'https://healthy.example.com',
    ]);

    $failingSchedule = SeoSchedule::create([
        'website_id' => $failingWebsite->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHours(2),
    ]);
    $healthySchedule = SeoSchedule::create([
        'website_id' => $healthyWebsite->id,
        'created_by' => $user->id,
        'frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'is_active' => true,
        'next_run_at' => now()->subHour(),
    ]);

    $mockService = $this->mock(RobotsSitemapService::class);
    $mockService->shouldReceive('getCrawlableUrls')
        ->with($failingWebsite->url)
        ->andThrow(new RuntimeException('Sitemap startup failed'));
    $mockService->shouldReceive('getCrawlableUrls')
        ->with($healthyWebsite->url)
        ->andReturn([$healthyWebsite->url]);

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertPushedOn('seo-checks', SeoHealthCheckJob::class);

    $failedCheck = SeoCheck::where('website_id', $failingWebsite->id)->sole();
    $pendingCheck = SeoCheck::where('website_id', $healthyWebsite->id)->sole();

    expect($failedCheck->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($failedCheck->failure_context)->toMatchArray([
            'failure_reason' => 'scheduled_startup_failed',
            'schedule_id' => $failingSchedule->id,
            'exception_message' => 'Sitemap startup failed',
        ])
        ->and($pendingCheck->status)->toBe(SeoCheck::STATUS_PENDING)
        ->and($pendingCheck->crawl_summary)->toMatchArray([
            'schedule_id' => $healthySchedule->id,
            'is_scheduled' => true,
        ]);
});

test('command does not fail seo check when follow-up processing fails after dispatch', function () {
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
        ->andReturn(['https://example.com']);

    Log::shouldReceive('info')
        ->once()
        ->andThrow(new RuntimeException('Log sink failed'));

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context = []) use ($schedule, $website) {
            return str_contains($message, "Scheduled SEO check was dispatched for website {$website->url}, but follow-up processing failed: Log sink failed")
                && ($context['schedule_id'] ?? null) === $schedule->id;
        });

    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();

    Queue::assertPushedOn('seo-checks', SeoHealthCheckJob::class);

    $seoCheck = SeoCheck::where('website_id', $website->id)->first();

    expect(SeoCheck::where('website_id', $website->id)->count())->toBe(1)
        ->and($seoCheck)->not->toBeNull()
        ->and($seoCheck->status)->toBe('pending')
        ->and($seoCheck->failure_summary)->toBeNull()
        ->and($seoCheck->failure_context)->toBeNull();
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
    expect($schedule->next_run_at->equalTo($originalNextRun))->toBeFalse();
    expect($schedule->next_run_at->isFuture())->toBeTrue();
});
