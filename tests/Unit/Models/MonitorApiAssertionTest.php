<?php

namespace Tests\Unit\Models;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use Tests\TestCase;

class MonitorApiAssertionTest extends TestCase
{
    public function test_monitor_api_assertion_belongs_to_monitor_api(): void
    {
        $monitor = MonitorApis::factory()->create();
        $assertion = MonitorApiAssertion::factory()->create(['monitor_api_id' => $monitor->id]);

        $this->assertInstanceOf(MonitorApis::class, $assertion->monitorApi);
        $this->assertEquals($monitor->id, $assertion->monitorApi->id);
    }

    public function test_assertion_validates_type_check_correctly(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'type_check',
            'expected_type' => 'string',
        ]);

        $result = $assertion->validateResponse('hello');
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse(123);
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_exists(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'exists',
        ]);

        $result = $assertion->validateResponse('value');
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse(null);
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_not_exists(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'not_exists',
        ]);

        $result = $assertion->validateResponse(null);
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse('value');
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_value_comparison_equals(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '=',
            'expected_value' => 'success',
        ]);

        $result = $assertion->validateResponse('success');
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse('error');
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_value_comparison_not_equals(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '!=',
            'expected_value' => 'error',
        ]);

        $result = $assertion->validateResponse('success');
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse('error');
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_greater_than(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '>',
            'expected_value' => '10',
        ]);

        $result = $assertion->validateResponse(15);
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse(5);
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_less_than(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '<',
            'expected_value' => '10',
        ]);

        $result = $assertion->validateResponse(5);
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse(15);
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_array_length(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'array_length',
            'comparison_operator' => '=',
            'expected_value' => '3',
        ]);

        $result = $assertion->validateResponse([1, 2, 3]);
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse([1, 2]);
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_array_length_requires_array(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'array_length',
            'comparison_operator' => '=',
            'expected_value' => '3',
        ]);

        $result = $assertion->validateResponse('not an array');
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('not an array', $result['message']);
    }

    public function test_assertion_validates_regex_match(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'regex_match',
            'regex_pattern' => '/^[a-z]+$/',
        ]);

        $result = $assertion->validateResponse('hello');
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse('Hello123');
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_regex_requires_string(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'regex_match',
            'regex_pattern' => '/test/',
        ]);

        $result = $assertion->validateResponse(123);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('not a string', $result['message']);
    }

    public function test_assertion_validates_contains_in_array(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => 'contains',
            'expected_value' => 'apple',
        ]);

        $result = $assertion->validateResponse(['apple', 'banana', 'orange']);
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse(['banana', 'orange']);
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_validates_contains_in_string(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => 'contains',
            'expected_value' => 'world',
        ]);

        $result = $assertion->validateResponse('hello world');
        $this->assertTrue($result['passed']);

        $result = $assertion->validateResponse('hello there');
        $this->assertFalse($result['passed']);
    }

    public function test_assertion_casts_expected_value_to_integer(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '=',
            'expected_value' => '42',
        ]);

        $result = $assertion->validateResponse(42);
        $this->assertTrue($result['passed']);
    }

    public function test_assertion_casts_expected_value_to_float(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '=',
            'expected_value' => '3.14',
        ]);

        $result = $assertion->validateResponse(3.14);
        $this->assertTrue($result['passed']);
    }

    public function test_assertion_casts_expected_value_to_boolean(): void
    {
        $assertion = MonitorApiAssertion::factory()->create([
            'assertion_type' => 'value_compare',
            'comparison_operator' => '=',
            'expected_value' => 'true',
        ]);

        $result = $assertion->validateResponse(true);
        $this->assertTrue($result['passed']);
    }

    public function test_assertion_has_sort_order(): void
    {
        $assertion = MonitorApiAssertion::factory()->create(['sort_order' => 5]);

        $this->assertEquals(5, $assertion->sort_order);
        $this->assertIsInt($assertion->sort_order);
    }

    public function test_assertion_can_be_active(): void
    {
        $assertion = MonitorApiAssertion::factory()->create(['is_active' => true]);

        $this->assertTrue($assertion->is_active);
    }

    public function test_assertion_can_be_inactive(): void
    {
        $assertion = MonitorApiAssertion::factory()->create(['is_active' => false]);

        $this->assertFalse($assertion->is_active);
    }
}
