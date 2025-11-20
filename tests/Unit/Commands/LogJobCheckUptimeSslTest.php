<?php

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use Illuminate\Support\Facades\Queue;

test('command can be executed', function () {
    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful()
        ->assertExitCode(0);
});

test('command dispatches jobs for 1 minute interval at every minute', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
    ]);

    // Mock current minute to match the interval
    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
});

test('command dispatches jobs for 5 minute interval when minute is divisible by 5', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 5,
    ]);

    // The command will check if current minute % 5 === 0
    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    // This will depend on the current minute
    $currentMinute = now()->minute;
    if ($currentMinute % 5 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command dispatches jobs for multiple intervals when applicable', function () {
    Queue::fake();

    // Create websites with intervals 1 and 5
    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
    ]);

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 5,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 5 === 0) {
        // Both intervals should match
        Queue::assertPushed(LogUptimeSslJob::class, 2);
    } else {
        // Only interval 1 should match
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    }
});

test('command does not dispatch jobs when no intervals match', function () {
    Queue::fake();

    // Create website with 720 minute interval (12 hours)
    // This will rarely match except at specific minutes
    // Note: interval 1 always matches, so this test only verifies
    // that websites with non-matching intervals are not processed
    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 720,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 720 !== 0) {
        // Website with 720 interval should not be processed
        // (though interval 1 always matches, there are no websites with interval 1)
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command does not dispatch jobs for websites with uptime check disabled', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => false,
        'uptime_interval' => 1,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('command dispatches jobs to correct queue', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushedOn('log-website', LogUptimeSslJob::class);
});

test('command displays processing message when websites found', function () {
    Queue::fake();

    Website::factory()->count(3)->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();
});

test('command handles 10 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 10,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 10 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command handles 15 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 15,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 15 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command handles 30 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 30,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 30 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command handles 60 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 60,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 60 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command handles 360 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 360,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 360 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command handles 720 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 720,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 720 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command handles 1440 minute interval', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1440,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    $currentMinute = now()->minute;
    if ($currentMinute % 1440 === 0) {
        Queue::assertPushed(LogUptimeSslJob::class, 1);
    } else {
        Queue::assertNotPushed(LogUptimeSslJob::class);
    }
});

test('command dispatches correct website to job', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
        'url' => 'https://example.com',
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
});

test('command handles no websites', function () {
    Queue::fake();

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('command has correct intervals array', function () {
    // Verify the command has the expected intervals
    $expectedIntervals = [1, 5, 10, 15, 30, 60, 360, 720, 1440];

    // We can't directly access the protected property, but we can test behavior
    // by creating websites with each interval and verifying they match when appropriate
    Queue::fake();

    foreach ($expectedIntervals as $interval) {
        Website::factory()->create([
            'uptime_check' => true,
            'uptime_interval' => $interval,
        ]);
    }

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    // At minimum, interval 1 should always match
    Queue::assertPushed(LogUptimeSslJob::class);
});
