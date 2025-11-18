<?php

namespace Tests\Unit\Jobs;

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LogUptimeSslJobTest extends TestCase
{
    public function test_job_creates_log_history_for_successful_check(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'uptime_check' => true,
        ]);

        $job = new LogUptimeSslJob($website);
        $job->handle();

        $this->assertDatabaseHas('website_log_history', [
            'website_id' => $website->id,
            'http_status_code' => 200,
        ]);
    }

    public function test_job_records_response_time(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'uptime_check' => true,
        ]);

        $job = new LogUptimeSslJob($website);
        $job->handle();

        $log = WebsiteLogHistory::where('website_id', $website->id)->first();

        $this->assertNotNull($log);
        $this->assertIsInt($log->speed);
        $this->assertGreaterThanOrEqual(0, $log->speed);
    }

    public function test_job_handles_failed_requests(): void
    {
        Http::fake([
            '*' => Http::response('', 500),
        ]);

        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'uptime_check' => true,
        ]);

        $job = new LogUptimeSslJob($website);
        $job->handle();

        $this->assertDatabaseHas('website_log_history', [
            'website_id' => $website->id,
            'http_status_code' => 500,
        ]);
    }

    public function test_job_skips_websites_with_uptime_check_disabled(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'uptime_check' => false,
        ]);

        $job = new LogUptimeSslJob($website);
        $job->handle();

        $this->assertDatabaseMissing('website_log_history', [
            'website_id' => $website->id,
        ]);
    }
}
