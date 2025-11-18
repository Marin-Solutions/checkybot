<?php

namespace Tests\Unit\Models;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\User;
use Tests\TestCase;

class MonitorApisTest extends TestCase
{
    public function test_monitor_api_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);

        $this->assertInstanceOf(User::class, $monitor->user);
        $this->assertEquals($user->id, $monitor->user->id);
    }

    public function test_monitor_api_has_many_assertions(): void
    {
        $monitor = MonitorApis::factory()->create();
        MonitorApiAssertion::factory()->count(3)->create(['monitor_api_id' => $monitor->id]);

        $this->assertCount(3, $monitor->assertions);
        $this->assertInstanceOf(MonitorApiAssertion::class, $monitor->assertions->first());
    }

    public function test_monitor_api_assertions_are_ordered_by_sort_order(): void
    {
        $monitor = MonitorApis::factory()->create();

        MonitorApiAssertion::factory()->create([
            'monitor_api_id' => $monitor->id,
            'sort_order' => 3,
        ]);
        MonitorApiAssertion::factory()->create([
            'monitor_api_id' => $monitor->id,
            'sort_order' => 1,
        ]);
        MonitorApiAssertion::factory()->create([
            'monitor_api_id' => $monitor->id,
            'sort_order' => 2,
        ]);

        $sortOrders = $monitor->fresh()->assertions->pluck('sort_order')->toArray();

        $this->assertEquals([1, 2, 3], $sortOrders);
    }

    public function test_monitor_api_has_many_results(): void
    {
        $monitor = MonitorApis::factory()->create();
        MonitorApiResult::factory()->count(10)->create(['monitor_api_id' => $monitor->id]);

        $this->assertCount(10, $monitor->results);
        $this->assertInstanceOf(MonitorApiResult::class, $monitor->results->first());
    }

    public function test_monitor_api_requires_title(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        MonitorApis::factory()->create(['title' => null]);
    }

    public function test_monitor_api_requires_url(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        MonitorApis::factory()->create(['url' => null]);
    }

    public function test_monitor_api_stores_headers_as_json(): void
    {
        $headers = [
            'Authorization' => 'Bearer token123',
            'Accept' => 'application/json',
        ];

        $monitor = MonitorApis::factory()->create([
            'headers' => json_encode($headers),
        ]);

        $this->assertEquals($headers, json_decode($monitor->headers, true));
    }

    public function test_monitor_api_has_data_path_for_response_extraction(): void
    {
        $monitor = MonitorApis::factory()->create([
            'data_path' => 'data.results.items',
        ]);

        $this->assertEquals('data.results.items', $monitor->data_path);
    }
}
