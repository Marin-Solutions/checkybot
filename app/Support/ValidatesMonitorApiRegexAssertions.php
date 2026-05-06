<?php

namespace App\Support;

use App\Models\MonitorApiAssertion;
use Illuminate\Validation\Validator;

trait ValidatesMonitorApiRegexAssertions
{
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
