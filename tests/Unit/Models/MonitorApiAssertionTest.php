<?php

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;

test('monitor api assertion belongs to monitor api', function () {
    $monitor = MonitorApis::factory()->create();
    $assertion = MonitorApiAssertion::factory()->create(['monitor_api_id' => $monitor->id]);

    expect($assertion->monitorApi)->toBeInstanceOf(MonitorApis::class);
    expect($assertion->monitorApi->id)->toBe($monitor->id);
});

test('assertion validates type check correctly', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'type_check',
        'expected_type' => 'string',
    ]);

    $result = $assertion->validateResponse('hello');
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse(123);
    expect($result['passed'])->toBeFalse();
});

test('assertion validates exists', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'exists',
    ]);

    $result = $assertion->validateResponse('value');
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse(null);
    expect($result['passed'])->toBeFalse();
});

test('assertion validates not exists', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'not_exists',
    ]);

    $result = $assertion->validateResponse(null);
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse('value');
    expect($result['passed'])->toBeFalse();
});

test('assertion validates value comparison equals', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'success',
    ]);

    $result = $assertion->validateResponse('success');
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse('error');
    expect($result['passed'])->toBeFalse();
});

test('assertion validates value comparison not equals', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '!=',
        'expected_value' => 'error',
    ]);

    $result = $assertion->validateResponse('success');
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse('error');
    expect($result['passed'])->toBeFalse();
});

test('assertion validates greater than', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '>',
        'expected_value' => '10',
    ]);

    $result = $assertion->validateResponse(15);
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse(5);
    expect($result['passed'])->toBeFalse();
});

test('assertion validates less than', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '<',
        'expected_value' => '10',
    ]);

    $result = $assertion->validateResponse(5);
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse(15);
    expect($result['passed'])->toBeFalse();
});

test('assertion validates array length', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'array_length',
        'comparison_operator' => '=',
        'expected_value' => '3',
    ]);

    $result = $assertion->validateResponse([1, 2, 3]);
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse([1, 2]);
    expect($result['passed'])->toBeFalse();
});

test('assertion validates array length requires array', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'array_length',
        'comparison_operator' => '=',
        'expected_value' => '3',
    ]);

    $result = $assertion->validateResponse('not an array');
    expect($result['passed'])->toBeFalse();
    expect($result['message'])->toContain('not an array');
});

test('assertion validates regex match', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'regex_match',
        'regex_pattern' => '/^[a-z]+$/',
    ]);

    $result = $assertion->validateResponse('hello');
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse('Hello123');
    expect($result['passed'])->toBeFalse();
});

test('assertion validates regex requires string', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'regex_match',
        'regex_pattern' => '/test/',
    ]);

    $result = $assertion->validateResponse(123);
    expect($result['passed'])->toBeFalse();
    expect($result['message'])->toContain('not a string');
});

test('assertion validates contains in array', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => 'contains',
        'expected_value' => 'apple',
    ]);

    $result = $assertion->validateResponse(['apple', 'banana', 'orange']);
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse(['banana', 'orange']);
    expect($result['passed'])->toBeFalse();
});

test('assertion validates contains in string', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => 'contains',
        'expected_value' => 'world',
    ]);

    $result = $assertion->validateResponse('hello world');
    expect($result['passed'])->toBeTrue();

    $result = $assertion->validateResponse('hello there');
    expect($result['passed'])->toBeFalse();
});

test('assertion casts expected value to integer', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => '42',
    ]);

    $result = $assertion->validateResponse(42);
    expect($result['passed'])->toBeTrue();
});

test('assertion casts expected value to float', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => '3.14',
    ]);

    $result = $assertion->validateResponse(3.14);
    expect($result['passed'])->toBeTrue();
});

test('assertion casts expected value to boolean', function () {
    $assertion = MonitorApiAssertion::factory()->create([
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'true',
    ]);

    $result = $assertion->validateResponse(true);
    expect($result['passed'])->toBeTrue();
});

test('assertion has sort order', function () {
    $assertion = MonitorApiAssertion::factory()->create(['sort_order' => 5]);

    expect($assertion->sort_order)->toBe(5);
    expect($assertion->sort_order)->toBeInt();
});

test('assertion can be active', function () {
    $assertion = MonitorApiAssertion::factory()->create(['is_active' => true]);

    expect($assertion->is_active)->toBeTrue();
});

test('assertion can be inactive', function () {
    $assertion = MonitorApiAssertion::factory()->create(['is_active' => false]);

    expect($assertion->is_active)->toBeFalse();
});
