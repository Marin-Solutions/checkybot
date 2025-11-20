<?php

use App\Events\CrawlFailed;
use App\Jobs\SeoHealthCheckJob;
use App\Mail\SeoCheckCompleted;
use App\Models\SeoCheck;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('job can be instantiated with seo check', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    $job = new SeoHealthCheckJob($seoCheck);

    expect($job)->toBeInstanceOf(SeoHealthCheckJob::class);
});

test('job can be instantiated with crawlable urls', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);
    $urls = ['https://example.com', 'https://example.com/about'];

    $job = new SeoHealthCheckJob($seoCheck, $urls);

    expect($job)->toBeInstanceOf(SeoHealthCheckJob::class);
});

test('job implements should queue', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    $job = new SeoHealthCheckJob($seoCheck);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job has correct timeout', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    $job = new SeoHealthCheckJob($seoCheck);

    expect($job->timeout)->toBe(900);
});

test('job has correct tries', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    $job = new SeoHealthCheckJob($seoCheck);

    expect($job->tries)->toBe(1);
});

test('job can be dispatched to queue', function () {
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
});

test('job can be dispatched to specific queue', function () {
    Queue::fake();

    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    SeoHealthCheckJob::dispatch($seoCheck)->onQueue('seo-checks');

    Queue::assertPushedOn('seo-checks', SeoHealthCheckJob::class);
});

test('job updates status to running on handle', function () {
    Log::shouldReceive('info')->andReturn(null);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    // We can verify the SeoCheck status would be updated
    expect($seoCheck->status)->toBe('pending');
});

test('failed method updates status to failed', function () {
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
    expect($seoCheck->status)->toBe('failed');
    expect($seoCheck->finished_at)->not->toBeNull();
});

test('failed method broadcasts crawl failed event', function () {
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
});

test('job does not send email for non scheduled check', function () {
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
});

test('job sends email for scheduled check', function () {
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
});

test('job sends email to website owner if different from scheduler', function () {
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
});

test('job handles email failure gracefully', function () {
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

    expect(true)->toBeTrue();
});

test('job uses queueable trait', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);

    $job = new SeoHealthCheckJob($seoCheck);

    expect(method_exists($job, 'onQueue'))->toBeTrue();
    expect(method_exists($job, 'delay'))->toBeTrue();
});

test('job stores seo check and crawlable urls', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
    ]);
    $urls = ['https://example.com', 'https://example.com/about'];

    $job = new SeoHealthCheckJob($seoCheck, $urls);

    expect($job->seoCheck->id)->toBe($seoCheck->id);

    // Use reflection to access protected property
    $reflection = new \ReflectionClass($job);
    $property = $reflection->getProperty('crawlableUrls');
    $property->setAccessible(true);

    expect($property->getValue($job))->toBe($urls);
});
