<?php

namespace Tests\Unit\Commands;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckApiMonitorsTest extends TestCase
{
    public function test_command_checks_all_active_api_monitors(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['status' => 'ok']], 200),
        ]);

        $monitor = MonitorApis::factory()->create([
            'url' => 'https://api.example.com/health',
            'data_path' => 'data.status',
        ]);

        MonitorApiAssertion::factory()->create([
            'monitor_api_id' => $monitor->id,
            'data_path' => 'data.status',
            'expected_value' => 'ok',
        ]);

        $this->artisan('monitor:check-apis')
            ->assertSuccessful();

        $this->assertDatabaseHas('monitor_api_results', [
            'monitor_api_id' => $monitor->id,
        ]);
    }

    public function test_command_records_failed_checks(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['status' => 'error']], 500),
        ]);

        $monitor = MonitorApis::factory()->create([
            'url' => 'https://api.example.com/health',
        ]);

        $this->artisan('monitor:check-apis')
            ->assertSuccessful();

        $this->assertDatabaseHas('monitor_api_results', [
            'monitor_api_id' => $monitor->id,
            'is_success' => false,
        ]);
    }

    public function test_command_validates_assertions(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['status' => 'ok']], 200),
        ]);

        $monitor = MonitorApis::factory()->create();

        MonitorApiAssertion::factory()->create([
            'monitor_api_id' => $monitor->id,
            'data_path' => 'data.status',
            'expected_value' => 'ok',
        ]);

        $this->artisan('monitor:check-apis')
            ->assertSuccessful();

        $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

        $this->assertTrue($result->is_success);
    }
}
