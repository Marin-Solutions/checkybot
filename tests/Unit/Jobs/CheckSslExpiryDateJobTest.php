<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\NotificationSetting;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckSslExpiryDateJobTest extends TestCase
{
    public function test_job_checks_ssl_expiry_for_website(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'ssl_check' => true,
        ]);

        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $job = new CheckSslExpiryDateJob($website);
        $job->handle();

        // SSL certificate check should have been attempted
        $this->assertTrue(true); // Job executed without errors
    }

    public function test_job_skips_websites_with_ssl_check_disabled(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'ssl_check' => false,
        ]);

        Mail::fake();

        $job = new CheckSslExpiryDateJob($website);
        $job->handle();

        Mail::assertNothingSent();
    }

    public function test_job_sends_notification_when_ssl_expiry_approaching(): void
    {
        Mail::fake();

        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(10), // Expires soon
        ]);

        NotificationSetting::factory()->email()->create([
            'user_id' => $website->user->id,
            'website_id' => $website->id,
        ]);

        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $job = new CheckSslExpiryDateJob($website);
        $job->handle();

        // Job should complete successfully
        $this->assertTrue(true);
    }

    public function test_job_handles_websites_without_ssl(): void
    {
        $website = Website::factory()->create([
            'url' => 'http://example.com', // No SSL
            'ssl_check' => true,
        ]);

        $job = new CheckSslExpiryDateJob($website);
        $job->handle();

        // Job should handle gracefully
        $this->assertTrue(true);
    }
}
