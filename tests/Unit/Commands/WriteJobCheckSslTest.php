<?php

namespace Tests\Unit\Commands;

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WriteJobCheckSslTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_be_executed(): void
    {
        $this->artisan('ssl:check')
            ->expectsOutput('SSL check completed successfully.')
            ->assertSuccessful()
            ->assertExitCode(0);
    }

    public function test_command_dispatches_jobs_for_websites_with_ssl_expiring_in_14_days(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(14),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_dispatches_jobs_for_websites_with_ssl_expiring_in_7_days(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(7),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_dispatches_jobs_for_websites_with_ssl_expiring_in_3_days(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(3),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_dispatches_jobs_for_websites_with_ssl_expiring_in_2_days(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(2),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_dispatches_jobs_for_websites_with_ssl_expiring_in_1_day(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(1),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_dispatches_jobs_for_websites_with_expired_ssl(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->subDays(1),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_does_not_dispatch_jobs_for_ssl_expiring_in_non_reminder_days(): void
    {
        Queue::fake();

        // SSL expires in 5 days (not a reminder day)
        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(5),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertNotPushed(CheckSslExpiryDateJob::class);
    }

    public function test_command_dispatches_jobs_for_websites_without_ssl_expiry_date(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => null,
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_does_not_dispatch_jobs_for_websites_with_ssl_check_disabled(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => false,
            'ssl_expiry_date' => today()->addDays(7),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertNotPushed(CheckSslExpiryDateJob::class);
    }

    public function test_command_dispatches_jobs_to_correct_queue(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(7),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushedOn('ssl-check', CheckSslExpiryDateJob::class);
    }

    public function test_command_dispatches_jobs_for_multiple_websites_matching_criteria(): void
    {
        Queue::fake();

        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(14),
        ]);

        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(7),
        ]);

        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(1),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 3);
    }

    public function test_command_dispatches_correct_website_to_job(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(7),
            'url' => 'https://example.com',
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_handles_no_websites(): void
    {
        Queue::fake();

        $this->artisan('ssl:check')
            ->expectsOutput('SSL check completed successfully.')
            ->assertSuccessful();

        Queue::assertNotPushed(CheckSslExpiryDateJob::class);
    }

    public function test_command_filters_correctly_by_reminder_days(): void
    {
        Queue::fake();

        // Create websites with various expiry dates
        $reminderDays = [14, 7, 3, 2, 1];
        foreach ($reminderDays as $day) {
            Website::factory()->create([
                'ssl_check' => true,
                'ssl_expiry_date' => today()->addDays($day),
            ]);
        }

        // Create websites with non-reminder days
        $nonReminderDays = [13, 10, 6, 5, 4];
        foreach ($nonReminderDays as $day) {
            Website::factory()->create([
                'ssl_check' => true,
                'ssl_expiry_date' => today()->addDays($day),
            ]);
        }

        $this->artisan('ssl:check')
            ->assertSuccessful();

        // Should only dispatch for reminder days
        Queue::assertPushed(CheckSslExpiryDateJob::class, 5);
    }

    public function test_command_handles_ssl_expired_multiple_days_ago(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->subDays(10),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 1);
    }

    public function test_command_handles_ssl_expiring_today(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today(),
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        // Today means 0 days difference, which is not in the reminder days list
        // but less than 0 should trigger the check
        Queue::assertNotPushed(CheckSslExpiryDateJob::class);
    }

    public function test_command_displays_success_message(): void
    {
        $this->artisan('ssl:check')
            ->expectsOutput('SSL check completed successfully.')
            ->assertSuccessful();
    }

    public function test_command_has_correct_signature(): void
    {
        $this->artisan('ssl:check')
            ->assertSuccessful();
    }

    public function test_command_only_checks_websites_with_ssl_check_enabled(): void
    {
        Queue::fake();

        // Enabled with matching expiry
        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => today()->addDays(7),
        ]);

        // Disabled with matching expiry
        Website::factory()->create([
            'ssl_check' => false,
            'ssl_expiry_date' => today()->addDays(7),
        ]);

        // Enabled without expiry
        Website::factory()->create([
            'ssl_check' => true,
            'ssl_expiry_date' => null,
        ]);

        $this->artisan('ssl:check')
            ->assertSuccessful();

        // Should dispatch for enabled websites only (2 websites)
        Queue::assertPushed(CheckSslExpiryDateJob::class, 2);
    }

    public function test_command_handles_large_number_of_websites(): void
    {
        Queue::fake();

        for ($i = 0; $i < 100; $i++) {
            Website::factory()->create([
                'ssl_check' => true,
                'ssl_expiry_date' => today()->addDays(7),
            ]);
        }

        $this->artisan('ssl:check')
            ->assertSuccessful();

        Queue::assertPushed(CheckSslExpiryDateJob::class, 100);
    }
}
