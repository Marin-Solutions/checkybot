<?php

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 4, 27)->setTime(12, 0));
});

if (! function_exists('seedScheduledWebsiteLog')) {
    function seedScheduledWebsiteLog(Website $website, \Illuminate\Support\Carbon $createdAt): WebsiteLogHistory
    {
        return WebsiteLogHistory::factory()
            ->create([
                'website_id' => $website->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
    }
}

test('command can be executed', function () {
    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful()
        ->assertExitCode(0);
});

test('command dispatches websites with no heartbeat immediately', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1440,
        'last_heartbeat_at' => null,
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
});

test('command dispatches websites when last heartbeat plus interval is due', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 60,
    ]);
    seedScheduledWebsiteLog($website, now()->subMinutes(60));

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
});

test('command floors heartbeat timestamps to the minute when checking due websites', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 60,
    ]);
    seedScheduledWebsiteLog($website, now()->subMinutes(60)->addSeconds(30));

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
});

test('command does not dispatch websites before their interval has elapsed', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 60,
    ]);
    seedScheduledWebsiteLog($website, now()->subMinutes(59));

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('command honors long website uptime intervals', function (int $interval) {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => $interval,
    ]);
    seedScheduledWebsiteLog($website, now()->subMinutes(60));

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
})->with([360, 720, 1440]);

test('command dispatches long website uptime intervals once elapsed', function (int $interval) {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => $interval,
    ]);
    seedScheduledWebsiteLog($website, now()->subMinutes($interval));

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
})->with([360, 720, 1440]);

test('command dispatches jobs to correct queue', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 1,
    ]);
    seedScheduledWebsiteLog($website, now()->subMinute());

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushedOn('log-website', LogUptimeSslJob::class);
});

test('command only dispatches due websites', function () {
    Queue::fake();

    $dueWebsite = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 5,
    ]);
    seedScheduledWebsiteLog($dueWebsite, now()->subMinutes(5));

    $skippedWebsite = Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 5,
    ]);
    seedScheduledWebsiteLog($skippedWebsite, now()->subMinutes(4));

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertPushed(LogUptimeSslJob::class, 1);
    Queue::assertPushed(LogUptimeSslJob::class, function (LogUptimeSslJob $job) use ($dueWebsite): bool {
        return $job->website->is($dueWebsite);
    });
});

test('command does not dispatch jobs for websites with uptime check disabled', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => false,
        'uptime_interval' => 1,
        'last_heartbeat_at' => now()->subMinute(),
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('command ignores websites with unsupported uptime intervals', function () {
    Queue::fake();

    Website::factory()->create([
        'uptime_check' => true,
        'uptime_interval' => 2,
        'last_heartbeat_at' => now()->subMinutes(2),
    ]);

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('command handles no websites', function () {
    Queue::fake();

    $this->artisan('website:log-uptime-ssl')
        ->assertSuccessful();

    Queue::assertNotPushed(LogUptimeSslJob::class);
});
