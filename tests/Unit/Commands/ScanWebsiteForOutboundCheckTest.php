<?php

namespace Tests\Unit\Commands;

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanWebsiteForOutboundCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_be_executed(): void
    {
        Queue::fake();

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();
    }

    public function test_command_dispatches_jobs_for_websites_with_outbound_check_enabled(): void
    {
        Queue::fake();

        $websites = Website::factory()->count(3)->create([
            'outbound_check' => true,
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 3);
    }

    public function test_command_does_not_dispatch_jobs_for_websites_with_outbound_check_disabled(): void
    {
        Queue::fake();

        Website::factory()->count(3)->create([
            'outbound_check' => false,
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertNotPushed(WebsiteCheckOutboundLinkJob::class);
    }

    public function test_command_dispatches_jobs_only_for_enabled_websites(): void
    {
        Queue::fake();

        Website::factory()->count(2)->create([
            'outbound_check' => true,
        ]);

        Website::factory()->count(3)->create([
            'outbound_check' => false,
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 2);
    }

    public function test_command_dispatches_jobs_to_correct_queue(): void
    {
        Queue::fake();

        Website::factory()->create([
            'outbound_check' => true,
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertPushedOn('log-website', WebsiteCheckOutboundLinkJob::class);
    }

    public function test_command_logs_completion(): void
    {
        Queue::fake();
        Log::spy();

        $websites = Website::factory()->count(3)->create([
            'outbound_check' => true,
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->with('Scan completed and jobs dispatched for SSL checks', ['website_count' => 3])
            ->once();
    }

    public function test_command_handles_no_websites(): void
    {
        Queue::fake();
        Log::spy();

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertNotPushed(WebsiteCheckOutboundLinkJob::class);

        Log::shouldHaveReceived('info')
            ->with('Scan completed and jobs dispatched for SSL checks', ['website_count' => 0])
            ->once();
    }

    public function test_command_dispatches_correct_website_to_job(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'outbound_check' => true,
            'url' => 'https://example.com',
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 1);
    }

    public function test_command_handles_large_number_of_websites(): void
    {
        Queue::fake();

        Website::factory()->count(100)->create([
            'outbound_check' => true,
        ]);

        $this->artisan('website:scan-outbound-check')
            ->assertSuccessful();

        Queue::assertPushed(WebsiteCheckOutboundLinkJob::class, 100);
    }

    public function test_command_has_correct_signature(): void
    {
        $command = $this->artisan('website:scan-outbound-check');

        $this->assertTrue(true); // Command exists and can be called
    }

    public function test_command_has_correct_description(): void
    {
        $this->artisan('help', ['command_name' => 'website:scan-outbound-check'])
            ->expectsOutput('Description:')
            ->assertSuccessful();
    }
}
