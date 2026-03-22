<?php

namespace MarinSolutions\CheckybotLaravel\Checks;

/**
 * Pending assertion builder for API checks.
 *
 * This class provides a fluent interface for building assertions
 * on JSON response paths, inspired by Pest's expectation API.
 *
 * @example
 * ```php
 * Checkybot::api('health')
 *     ->url('https://example.com/api/health')
 *     ->expect('status')->toEqual('healthy')
 *     ->expect('database.connected')->toBeTrue()
 *     ->expect('queue.size')->toBeLessThan(100);
 * ```
 */
class PendingAssertion
{
    /**
     * The parent API check.
     */
    protected ApiCheck $check;

    /**
     * The JSON path being asserted on.
     */
    protected string $path;

    /**
     * Create a new pending assertion.
     *
     * @param  ApiCheck  $check  Parent API check
     * @param  string  $path  JSON path to assert on
     */
    public function __construct(ApiCheck $check, string $path)
    {
        $this->check = $check;
        $this->path = $path;
    }

    /**
     * Assert that the path exists in the response.
     *
     *
     * @example
     * ```php
     * ->expect('status')->toExist()
     * ```
     */
    public function toExist(): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'exists',
        ]);
    }

    /**
     * Alias for toExist().
     */
    public function exists(): ApiCheck
    {
        return $this->toExist();
    }

    /**
     * Assert that the value equals the expected value.
     *
     * @param  mixed  $expected  Expected value
     *
     * @example
     * ```php
     * ->expect('status')->toEqual('healthy')
     * ```
     */
    public function toEqual(mixed $expected): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'value_compare',
            'comparison_operator' => '=',
            'expected_value' => (string) $expected,
        ]);
    }

    /**
     * Alias for toEqual().
     *
     * @param  mixed  $expected  Expected value
     */
    public function toBe(mixed $expected): ApiCheck
    {
        return $this->toEqual($expected);
    }

    /**
     * Alias for toEqual().
     *
     * @param  mixed  $expected  Expected value
     */
    public function equals(mixed $expected): ApiCheck
    {
        return $this->toEqual($expected);
    }

    /**
     * Assert that the value does not equal the expected value.
     *
     * @param  mixed  $expected  Value that should not match
     *
     * @example
     * ```php
     * ->expect('status')->notToEqual('error')
     * ```
     */
    public function notToEqual(mixed $expected): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'value_compare',
            'comparison_operator' => '!=',
            'expected_value' => (string) $expected,
        ]);
    }

    /**
     * Alias for notToEqual().
     *
     * @param  mixed  $expected  Value that should not match
     */
    public function notToBe(mixed $expected): ApiCheck
    {
        return $this->notToEqual($expected);
    }

    /**
     * Assert that the value is greater than the expected value.
     *
     * @param  int|float  $expected  Threshold value
     *
     * @example
     * ```php
     * ->expect('uptime_percentage')->toBeGreaterThan(99)
     * ```
     */
    public function toBeGreaterThan(int|float $expected): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'value_compare',
            'comparison_operator' => '>',
            'expected_value' => (string) $expected,
        ]);
    }

    /**
     * Assert that the value is greater than or equal to the expected value.
     *
     * @param  int|float  $expected  Threshold value
     *
     * @example
     * ```php
     * ->expect('workers')->toBeGreaterThanOrEqual(1)
     * ```
     */
    public function toBeGreaterThanOrEqual(int|float $expected): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'value_compare',
            'comparison_operator' => '>=',
            'expected_value' => (string) $expected,
        ]);
    }

    /**
     * Assert that the value is less than the expected value.
     *
     * @param  int|float  $expected  Threshold value
     *
     * @example
     * ```php
     * ->expect('queue_size')->toBeLessThan(1000)
     * ```
     */
    public function toBeLessThan(int|float $expected): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'value_compare',
            'comparison_operator' => '<',
            'expected_value' => (string) $expected,
        ]);
    }

    /**
     * Assert that the value is less than or equal to the expected value.
     *
     * @param  int|float  $expected  Threshold value
     *
     * @example
     * ```php
     * ->expect('error_rate')->toBeLessThanOrEqual(0.01)
     * ```
     */
    public function toBeLessThanOrEqual(int|float $expected): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'value_compare',
            'comparison_operator' => '<=',
            'expected_value' => (string) $expected,
        ]);
    }

    /**
     * Assert that the value is true.
     *
     *
     * @example
     * ```php
     * ->expect('database.connected')->toBeTrue()
     * ```
     */
    public function toBeTrue(): ApiCheck
    {
        return $this->toEqual('true');
    }

    /**
     * Assert that the value is false.
     *
     *
     * @example
     * ```php
     * ->expect('maintenance_mode')->toBeFalse()
     * ```
     */
    public function toBeFalse(): ApiCheck
    {
        return $this->toEqual('false');
    }

    /**
     * Assert that the value is of a specific type.
     *
     * @param  string  $type  Expected type (string, integer, boolean, array, object)
     *
     * @example
     * ```php
     * ->expect('user_count')->toBeType('integer')
     * ```
     */
    public function toBeType(string $type): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'type_check',
            'expected_type' => $type,
        ]);
    }

    /**
     * Assert that the value is a string.
     */
    public function toBeString(): ApiCheck
    {
        return $this->toBeType('string');
    }

    /**
     * Assert that the value is an integer.
     */
    public function toBeInteger(): ApiCheck
    {
        return $this->toBeType('integer');
    }

    /**
     * Alias for toBeInteger().
     */
    public function toBeInt(): ApiCheck
    {
        return $this->toBeInteger();
    }

    /**
     * Assert that the value is a boolean.
     */
    public function toBeBoolean(): ApiCheck
    {
        return $this->toBeType('boolean');
    }

    /**
     * Alias for toBeBoolean().
     */
    public function toBeBool(): ApiCheck
    {
        return $this->toBeBoolean();
    }

    /**
     * Assert that the value is an array.
     */
    public function toBeArray(): ApiCheck
    {
        return $this->toBeType('array');
    }

    /**
     * Assert that the value is an object.
     */
    public function toBeObject(): ApiCheck
    {
        return $this->toBeType('object');
    }

    /**
     * Assert that the value matches a regex pattern.
     *
     * @param  string  $pattern  Regular expression pattern
     *
     * @example
     * ```php
     * ->expect('version')->toMatch('/^v\d+\.\d+\.\d+$/')
     * ```
     */
    public function toMatch(string $pattern): ApiCheck
    {
        return $this->check->addAssertion([
            'data_path' => $this->path,
            'assertion_type' => 'regex_match',
            'regex_pattern' => $pattern,
        ]);
    }

    /**
     * Alias for toMatch().
     *
     * @param  string  $pattern  Regular expression pattern
     */
    public function toMatchRegex(string $pattern): ApiCheck
    {
        return $this->toMatch($pattern);
    }
}
