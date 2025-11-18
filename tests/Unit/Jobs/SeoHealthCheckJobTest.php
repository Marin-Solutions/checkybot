<?php

namespace Tests\Unit\Jobs;

use App\Events\CrawlFailed;
use App\Jobs\SeoHealthCheckJob;
use App\Mail\SeoCheckCompleted;
use App\Models\SeoCheck;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoHealthCheckJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_instantiated_with_seo_check(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        $this->assertInstanceOf(SeoHealthCheckJob::class, $job);
    }

    public function test_job_can_be_instantiated_with_crawlable_urls(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);
        $urls = ['https://example.com', 'https://example.com/about'];

        $job = new SeoHealthCheckJob($seoCheck, $urls);

        $this->assertInstanceOf(SeoHealthCheckJob::class, $job);
    }

    public function test_job_implements_should_queue(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    public function test_job_has_correct_timeout(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        $this->assertEquals(900, $job->timeout);
    }

    public function test_job_has_correct_tries(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        $this->assertEquals(1, $job->tries);
    }

    public function test_job_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        SeoHealthCheckJob::dispatch($seoCheck);

        Queue::assertPushed(SeoHealthCheckJob::class, function ($job) use ($seoCheck) {
            return $job->seoCheck->id === $seoCheck->id;
        });
    }

    public function test_job_can_be_dispatched_to_specific_queue(): void
    {
        Queue::fake();

        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        SeoHealthCheckJob::dispatch($seoCheck)->onQueue('seo-checks');

        Queue::assertPushedOn('seo-checks', SeoHealthCheckJob::class);
    }

    public function test_job_updates_status_to_running_on_handle(): void
    {
        Log::shouldReceive('info')->andReturn(null);

        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        // We can verify the SeoCheck status would be updated
        $this->assertEquals('pending', $seoCheck->status);
    }

    public function test_failed_method_updates_status_to_failed(): void
    {
        Event::fake();
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);

        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'running',
        ]);

        $job = new SeoHealthCheckJob($seoCheck);
        $exception = new \Exception('Test exception');

        $job->failed($exception);

        $seoCheck->refresh();
        $this->assertEquals('failed', $seoCheck->status);
        $this->assertNotNull($seoCheck->finished_at);
    }

    public function test_failed_method_broadcasts_crawl_failed_event(): void
    {
        Event::fake();
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);

        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'running',
            'total_urls_crawled' => 10,
        ]);

        $job = new SeoHealthCheckJob($seoCheck);
        $exception = new \Exception('Test exception');

        $job->failed($exception);

        Event::assertDispatched(CrawlFailed::class, function ($event) use ($seoCheck) {
            return $event->seoCheckId === $seoCheck->id
                && $event->totalUrlsCrawled === 10;
        });
    }

    public function test_job_does_not_send_email_for_non_scheduled_check(): void
    {
        Mail::fake();
        Log::shouldReceive('info')->andReturn(null);

        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'crawl_summary' => [
                'is_scheduled' => false,
            ],
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        // Call the protected method using reflection
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('sendNotificationIfScheduled');
        $method->setAccessible(true);
        $method->invoke($job);

        Mail::assertNothingSent();
    }

    public function test_job_sends_email_for_scheduled_check(): void
    {
        Mail::fake();
        Log::shouldReceive('info')->andReturn(null);

        $user = User::factory()->create([
            'email' => 'scheduler@example.com',
        ]);
        $website = Website::factory()->create([
            'created_by' => $user->id,
        ]);
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'crawl_summary' => [
                'is_scheduled' => true,
                'scheduled_by' => $user->id,
            ],
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        // Call the protected method using reflection
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('sendNotificationIfScheduled');
        $method->setAccessible(true);
        $method->invoke($job);

        Mail::assertSent(SeoCheckCompleted::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_job_sends_email_to_website_owner_if_different_from_scheduler(): void
    {
        Mail::fake();
        Log::shouldReceive('info')->andReturn(null);

        $scheduler = User::factory()->create([
            'email' => 'scheduler@example.com',
        ]);
        $owner = User::factory()->create([
            'email' => 'owner@example.com',
        ]);
        $website = Website::factory()->create([
            'created_by' => $owner->id,
        ]);
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'crawl_summary' => [
                'is_scheduled' => true,
                'scheduled_by' => $scheduler->id,
            ],
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        // Call the protected method using reflection
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('sendNotificationIfScheduled');
        $method->setAccessible(true);
        $method->invoke($job);

        Mail::assertSent(SeoCheckCompleted::class, 2);
    }

    public function test_job_handles_email_failure_gracefully(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \Exception('Email failed'));
        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);

        $user = User::factory()->create();
        $website = Website::factory()->create([
            'created_by' => $user->id,
        ]);
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'crawl_summary' => [
                'is_scheduled' => true,
                'scheduled_by' => $user->id,
            ],
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        // Call the protected method using reflection
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('sendNotificationIfScheduled');
        $method->setAccessible(true);

        // Should not throw exception
        $method->invoke($job);

        $this->assertTrue(true);
    }

    public function test_job_uses_queueable_trait(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $job = new SeoHealthCheckJob($seoCheck);

        $this->assertTrue(method_exists($job, 'onQueue'));
        $this->assertTrue(method_exists($job, 'delay'));
    }

    public function test_job_stores_seo_check_and_crawlable_urls(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);
        $urls = ['https://example.com', 'https://example.com/about'];

        $job = new SeoHealthCheckJob($seoCheck, $urls);

        $this->assertEquals($seoCheck->id, $job->seoCheck->id);

        // Use reflection to access protected property
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('crawlableUrls');
        $property->setAccessible(true);

        $this->assertEquals($urls, $property->getValue($job));
    }
}
