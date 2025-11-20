<?php

namespace Tests\Unit\Models;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Tests\TestCase;

class MonitorApiResultTest extends TestCase
{
    public function test_monitor_api_result_belongs_to_monitor_api(): void
    {
        $monitor = MonitorApis::factory()->create();
        $result = MonitorApiResult::factory()->create(['monitor_api_id' => $monitor->id]);

        $this->assertInstanceOf(MonitorApis::class, $result->monitorApi);
        $this->assertEquals($monitor->id, $result->monitorApi->id);
    }

    public function test_monitor_api_result_can_be_successful(): void
    {
        $result = MonitorApiResult::factory()->successful()->create();

        $this->assertTrue($result->is_success);
        $this->assertEquals(200, $result->http_code);
        $this->assertNull($result->failed_assertions);
    }

    public function test_monitor_api_result_can_be_failed(): void
    {
        $result = MonitorApiResult::factory()->failed()->create();

        $this->assertFalse($result->is_success);
        $this->assertNotEquals(200, $result->http_code);
        $this->assertNotNull($result->failed_assertions);
    }

    public function test_monitor_api_result_casts_is_success_to_boolean(): void
    {
        $result = MonitorApiResult::factory()->create(['is_success' => 1]);

        $this->assertIsBool($result->is_success);
    }

    public function test_monitor_api_result_casts_response_time_to_integer(): void
    {
        $result = MonitorApiResult::factory()->create(['response_time_ms' => '150']);

        $this->assertIsInt($result->response_time_ms);
        $this->assertEquals(150, $result->response_time_ms);
    }

    public function test_monitor_api_result_casts_http_code_to_integer(): void
    {
        $result = MonitorApiResult::factory()->create(['http_code' => '200']);

        $this->assertIsInt($result->http_code);
        $this->assertEquals(200, $result->http_code);
    }

    public function test_monitor_api_result_casts_failed_assertions_to_array(): void
    {
        $result = MonitorApiResult::factory()->create([
            'failed_assertions' => ['error' => 'Test failed'],
        ]);

        $this->assertIsArray($result->failed_assertions);
        $this->assertEquals(['error' => 'Test failed'], $result->failed_assertions);
    }

    public function test_monitor_api_result_casts_response_body_to_array(): void
    {
        $result = MonitorApiResult::factory()->create([
            'response_body' => ['data' => ['status' => 'ok']],
        ]);

        $this->assertIsArray($result->response_body);
        $this->assertEquals(['data' => ['status' => 'ok']], $result->response_body);
    }

    public function test_record_result_creates_successful_result(): void
    {
        $monitor = MonitorApis::factory()->create();
        $startTime = microtime(true);

        $testResult = [
            'code' => 200,
            'body' => ['status' => 'ok'],
            'assertions' => [
                ['passed' => true, 'path' => 'status', 'message' => 'OK'],
            ],
        ];

        $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

        $this->assertTrue($result->is_success);
        $this->assertEquals(200, $result->http_code);
        $this->assertEmpty($result->failed_assertions);
        $this->assertGreaterThanOrEqual(0, $result->response_time_ms);
    }

    public function test_record_result_creates_failed_result_with_assertions(): void
    {
        $monitor = MonitorApis::factory()->create();
        $startTime = microtime(true);

        $testResult = [
            'code' => 500,
            'body' => ['status' => 'error'],
            'assertions' => [
                [
                    'passed' => false,
                    'path' => 'status',
                    'type' => 'value_compare',
                    'message' => 'Expected ok, got error',
                ],
            ],
        ];

        $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

        $this->assertFalse($result->is_success);
        $this->assertEquals(500, $result->http_code);
        $this->assertNotEmpty($result->failed_assertions);
        $this->assertCount(1, $result->failed_assertions);
        $this->assertEquals('status', $result->failed_assertions[0]['path']);
    }

    public function test_record_result_only_saves_response_body_on_error(): void
    {
        $monitor = MonitorApis::factory()->create();
        $startTime = microtime(true);

        $successResult = [
            'code' => 200,
            'body' => ['status' => 'ok'],
            'assertions' => [['passed' => true]],
        ];

        $result = MonitorApiResult::recordResult($monitor, $successResult, $startTime);
        $this->assertNull($result->response_body);

        $failedResult = [
            'code' => 500,
            'body' => ['status' => 'error'],
            'assertions' => [['passed' => false, 'message' => 'Failed']],
        ];

        $result = MonitorApiResult::recordResult($monitor, $failedResult, $startTime);
        $this->assertNotNull($result->response_body);
    }

    public function test_record_result_calculates_response_time(): void
    {
        $monitor = MonitorApis::factory()->create();
        $startTime = microtime(true) - 0.15; // 150ms ago

        $testResult = [
            'code' => 200,
            'body' => [],
            'assertions' => [],
        ];

        $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

        $this->assertGreaterThan(100, $result->response_time_ms);
        $this->assertLessThan(200, $result->response_time_ms);
    }
}
