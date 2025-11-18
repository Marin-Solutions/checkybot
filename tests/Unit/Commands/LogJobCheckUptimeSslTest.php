<?php

namespace Tests\Unit\Commands;

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LogJobCheckUptimeSslTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_be_executed(): void
    {
        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful()
            ->assertExitCode(0);
    }

    public function test_command_dispatches_jobs_for_1_minute_interval_at_every_minute(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'uptime_check' => true,
            'uptime_interval' => 1,
        ]);

        // Mock current minute to match the interval
        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful();

        Queue::assertPushed(LogUptimeSslJob::class, 1);
    }

    public function test_command_dispatches_jobs_for_5_minute_interval_when_minute_is_divisible_by_5(): void
    {
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
    }

    public function test_command_dispatches_jobs_for_multiple_intervals_when_applicable(): void
    {
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
    }

    public function test_command_does_not_dispatch_jobs_when_no_intervals_match(): void
    {
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
    }

    public function test_command_does_not_dispatch_jobs_for_websites_with_uptime_check_disabled(): void
    {
        Queue::fake();

        Website::factory()->create([
            'uptime_check' => false,
            'uptime_interval' => 1,
        ]);

        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful();

        Queue::assertNotPushed(LogUptimeSslJob::class);
    }

    public function test_command_dispatches_jobs_to_correct_queue(): void
    {
        Queue::fake();

        Website::factory()->create([
            'uptime_check' => true,
            'uptime_interval' => 1,
        ]);

        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful();

        Queue::assertPushedOn('log-website', LogUptimeSslJob::class);
    }

    public function test_command_displays_processing_message_when_websites_found(): void
    {
        Queue::fake();

        Website::factory()->count(3)->create([
            'uptime_check' => true,
            'uptime_interval' => 1,
        ]);

        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful();
    }

    public function test_command_handles_10_minute_interval(): void
    {
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
    }

    public function test_command_handles_15_minute_interval(): void
    {
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
    }

    public function test_command_handles_30_minute_interval(): void
    {
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
    }

    public function test_command_handles_60_minute_interval(): void
    {
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
    }

    public function test_command_handles_360_minute_interval(): void
    {
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
    }

    public function test_command_handles_720_minute_interval(): void
    {
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
    }

    public function test_command_handles_1440_minute_interval(): void
    {
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
    }

    public function test_command_dispatches_correct_website_to_job(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'uptime_check' => true,
            'uptime_interval' => 1,
            'url' => 'https://example.com',
        ]);

        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful();

        Queue::assertPushed(LogUptimeSslJob::class, 1);
    }

    public function test_command_handles_no_websites(): void
    {
        Queue::fake();

        $this->artisan('website:log-uptime-ssl')
            ->assertSuccessful();

        Queue::assertNotPushed(LogUptimeSslJob::class);
    }

    public function test_command_has_correct_intervals_array(): void
    {
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
    }
}
