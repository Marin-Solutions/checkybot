<?php

namespace App\Support;

use App\Models\MonitorApiAssertion;
use Illuminate\Validation\Validator;

trait ValidatesMonitorApiRegexAssertions
{
    /**
     * @param  array<int, mixed>  $assertions
     */
    protected function addExpectedValueShapeValidationErrors(
        Validator $validator,
        array $assertions,
        string $attributePrefix,
    ): void {
        foreach ($assertions as $index => $assertion) {
            if (! is_array($assertion)) {
                continue;
            }

            if (! array_key_exists('expected_value', $assertion) || is_scalar($assertion['expected_value']) || $assertion['expected_value'] === null) {
                continue;
            }

            $validator->errors()->add(
                "{$attributePrefix}.{$index}.expected_value",
                'The expected value must be a string, number, boolean, or null. Arrays and objects are not supported.'
            );
        }
    }

    /**
     * @param  array<int, mixed>  $assertions
     */
    protected function addRegexAssertionValidationErrors(
        Validator $validator,
        array $assertions,
        string $attributePrefix,
        string $typeKey = 'type',
    ): void {
        foreach ($assertions as $index => $assertion) {
            if (! is_array($assertion)) {
                continue;
            }

            if (($assertion[$typeKey] ?? null) !== 'regex_match') {
                continue;
            }

            $pattern = $assertion['regex_pattern'] ?? null;

            if (! is_string($pattern) || ! MonitorApiAssertion::hasValidRegexPattern($pattern)) {
                $validator->errors()->add(
                    "{$attributePrefix}.{$index}.regex_pattern",
                    'The regex pattern must be a valid PHP regular expression.'
                );
            }
        }
    }
}
