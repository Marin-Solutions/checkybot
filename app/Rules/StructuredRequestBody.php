<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class StructuredRequestBody implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $bodyType = Arr::get($this->data, preg_replace('/request_body$/', 'request_body_type', $attribute) ?? '');

        if ($bodyType === 'raw') {
            if (! is_string($value)) {
                $fail("The {$attribute} field must be a string for raw request bodies.");
            }

            return;
        }

        if (! in_array($bodyType, ['json', 'form'], true)) {
            return;
        }

        if (is_array($value)) {
            return;
        }

        if (! is_string($value)) {
            $fail("The {$attribute} field must be a JSON object or array for {$bodyType} request bodies.");

            return;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return;
        }

        $fail("The {$attribute} field must be a JSON object or array for {$bodyType} request bodies.");
    }
}
