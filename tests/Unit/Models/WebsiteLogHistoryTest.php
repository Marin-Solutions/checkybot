<?php

namespace Tests\Unit\Models;

use App\Models\WebsiteLogHistory;
use Tests\TestCase;

class WebsiteLogHistoryTest extends TestCase
{
    public function test_website_log_history_has_correct_table_name(): void
    {
        $log = new WebsiteLogHistory;

        $this->assertEquals('website_log_history', $log->getTable());
    }

    public function test_website_log_history_has_fillable_attributes(): void
    {
        $website = \App\Models\Website::factory()->create();
        $log = WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'ssl_expiry_date' => now()->addMonths(3),
            'http_status_code' => 200,
            'speed' => 350,
        ]);

        $this->assertEquals($website->id, $log->website_id);
        $this->assertNotNull($log->ssl_expiry_date);
        $this->assertEquals(200, $log->http_status_code);
        $this->assertEquals(350, $log->speed);
    }

    public function test_website_log_history_records_successful_response(): void
    {
        $log = WebsiteLogHistory::factory()->create([
            'http_status_code' => 200,
        ]);

        $this->assertEquals(200, $log->http_status_code);
    }

    public function test_website_log_history_records_error_response(): void
    {
        $log = WebsiteLogHistory::factory()->error()->create();

        $this->assertNotEquals(200, $log->http_status_code);
        $this->assertContains($log->http_status_code, [404, 500, 503]);
    }

    public function test_website_log_history_records_slow_response(): void
    {
        $log = WebsiteLogHistory::factory()->slow()->create();

        $this->assertGreaterThan(2000, $log->speed);
    }

    public function test_website_log_history_can_track_ssl_expiry(): void
    {
        $expiryDate = now()->addMonths(2);
        $log = WebsiteLogHistory::factory()->create([
            'ssl_expiry_date' => $expiryDate,
        ]);

        $this->assertEquals(
            $expiryDate->format('Y-m-d H:i'),
            $log->ssl_expiry_date->format('Y-m-d H:i')
        );
    }

    public function test_website_log_history_tracks_response_time(): void
    {
        $log = WebsiteLogHistory::factory()->create();

        $this->assertNotNull($log->speed);
        $this->assertIsInt($log->speed);
    }
}
