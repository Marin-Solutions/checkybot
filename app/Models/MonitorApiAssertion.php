<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorApiAssertion extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitor_api_id',
        'data_path',
        'assertion_type',
        'expected_type',
        'comparison_operator',
        'expected_value',
        'regex_pattern',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function monitorApi(): BelongsTo
    {
        return $this->belongsTo(MonitorApis::class, 'monitor_api_id');
    }

    /**
     * @return array{passed: bool, message: string, actual: mixed, expected: mixed}
     */
    public function validateResponse($value, bool $exists = true): array
    {
        $result = [
            'passed' => false,
            'message' => '',
            'actual' => null,
            'expected' => null,
        ];

        switch ($this->assertion_type) {
            case 'type_check':
                $actualType = $this->getValueType($value);
                $result['passed'] = $actualType === $this->expected_type;
                $result['message'] = $result['passed']
                    ? "Value is of type {$this->expected_type}"
                    : "Expected type {$this->expected_type}, got {$actualType}";
                $result['actual'] = $actualType;
                $result['expected'] = $this->expected_type;
                break;

            case 'value_compare':
                $result = $this->compareValue($value);
                break;

            case 'exists':
                $result['passed'] = $exists;
                $result['message'] = $result['passed']
                    ? 'Value exists at path'
                    : 'Value does not exist at path';
                $result['actual'] = $exists ? 'exists' : 'missing';
                $result['expected'] = 'exists';
                break;

            case 'not_exists':
                $result['passed'] = ! $exists;
                $result['message'] = $result['passed']
                    ? 'Value does not exist at path'
                    : 'Value exists at path but should not';
                $result['actual'] = $exists ? 'exists' : 'missing';
                $result['expected'] = 'missing';
                break;

            case 'array_length':
                if (! is_array($value)) {
                    $result['message'] = 'Value is not an array';
                    $result['actual'] = $this->getValueType($value);
                    $result['expected'] = "array (length {$this->comparison_operator} {$this->expected_value})";
                    break;
                }
                $length = count($value);
                $result = $this->compareValue($length);
                $result['actual'] = $length;
                $result['expected'] = "{$this->comparison_operator} {$this->expected_value}";
                break;

            case 'regex_match':
                if (! is_string($value)) {
                    $result['message'] = 'Value is not a string';
                    $result['actual'] = $this->getValueType($value);
                    $result['expected'] = "string matching {$this->regex_pattern}";
                    break;
                }
                $result['passed'] = (bool) preg_match($this->regex_pattern, $value);
                $result['message'] = $result['passed']
                    ? 'Value matches pattern'
                    : 'Value does not match pattern';
                $result['actual'] = $value;
                $result['expected'] = $this->regex_pattern;
                break;
        }

        return $result;
    }

    private function getValueType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value)) {
            return 'array';
        }
        if (is_object($value)) {
            return 'object';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_null($value)) {
            return 'null';
        }

        return 'unknown';
    }

    /**
     * @return array{passed: bool, message: string, actual: mixed, expected: string}
     */
    private function compareValue($value): array
    {
        $result = ['passed' => false, 'message' => '', 'actual' => $value, 'expected' => "{$this->comparison_operator} {$this->expected_value}"];

        // Convert expected value to appropriate type if needed
        $typedExpectedValue = $this->castExpectedValue($value);

        switch ($this->comparison_operator) {
            case '=':
                $result['passed'] = $value == $typedExpectedValue;
                break;
            case '!=':
                $result['passed'] = $value != $typedExpectedValue;
                break;
            case '>':
                $result['passed'] = $value > $typedExpectedValue;
                break;
            case '<':
                $result['passed'] = $value < $typedExpectedValue;
                break;
            case '>=':
                $result['passed'] = $value >= $typedExpectedValue;
                break;
            case '<=':
                $result['passed'] = $value <= $typedExpectedValue;
                break;
            case 'contains':
                if (is_array($value)) {
                    $result['passed'] = in_array($typedExpectedValue, $value);
                } elseif (is_string($value)) {
                    $result['passed'] = str_contains($value, $this->expected_value);
                }
                break;
        }

        $result['message'] = $result['passed']
            ? 'Value comparison passed'
            : "Value comparison failed: expected {$this->comparison_operator} {$this->expected_value}";

        return $result;
    }

    private function castExpectedValue($actualValue): mixed
    {
        if (is_bool($actualValue)) {
            return filter_var($this->expected_value, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($actualValue)) {
            return (int) $this->expected_value;
        }
        if (is_float($actualValue)) {
            return (float) $this->expected_value;
        }

        return $this->expected_value;
    }
}
