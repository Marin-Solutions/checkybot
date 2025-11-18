<?php

namespace Tests\Unit\Commands;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Services\RobotsSitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunScheduledSeoChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_be_executed(): void
    {
        $this->artisan('seo:run-scheduled')
            ->assertSuccessful()
            ->assertExitCode(0);
    }

    public function test_command_finds_no_scheduled_checks(): void
    {
        $this->artisan('seo:run-scheduled')
            ->expectsOutput('Checking for scheduled SEO health checks...')
            ->expectsOutput('No scheduled SEO checks are due to run.')
            ->assertSuccessful();
    }

    public function test_command_dispatches_job_for_due_schedule(): void
    {
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
    }

    public function test_command_creates_seo_check_for_due_schedule(): void
    {
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

        $this->assertDatabaseHas('seo_checks', [
            'website_id' => $website->id,
            'status' => 'pending',
        ]);
    }

    public function test_command_updates_schedule_next_run_time(): void
    {
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
        $this->assertNotEquals($originalNextRun, $schedule->next_run_at);
        $this->assertNotNull($schedule->last_run_at);
    }

    public function test_command_skips_schedules_not_due(): void
    {
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
    }

    public function test_command_skips_inactive_schedules(): void
    {
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
    }

    public function test_command_skips_schedules_with_no_crawlable_urls(): void
    {
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
    }

    public function test_command_processes_multiple_due_schedules(): void
    {
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
    }

    public function test_command_dispatches_job_to_correct_queue(): void
    {
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
    }

    public function test_command_creates_seo_check_with_correct_data(): void
    {
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

        $this->assertDatabaseHas('seo_checks', [
            'website_id' => $website->id,
            'status' => 'pending',
            'total_crawlable_urls' => 2,
            'sitemap_used' => true,
            'robots_txt_checked' => true,
        ]);

        $seoCheck = SeoCheck::where('website_id', $website->id)->first();
        $this->assertEquals($user->id, $seoCheck->crawl_summary['scheduled_by']);
        $this->assertEquals($schedule->id, $seoCheck->crawl_summary['schedule_id']);
        $this->assertTrue($seoCheck->crawl_summary['is_scheduled']);
    }

    public function test_command_handles_exceptions_gracefully(): void
    {
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
    }

    public function test_command_displays_progress_bar(): void
    {
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
    }

    public function test_command_logs_scheduled_check_start(): void
    {
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
    }
}
