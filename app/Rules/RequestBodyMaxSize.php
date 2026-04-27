<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RequestBodyMaxSize implements ValidationRule
{
    public function __construct(
        private readonly int $maxBytes = 65535,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $payload = is_string($value) ? $value : json_encode($value);

        if (! is_string($payload) || strlen($payload) > $this->maxBytes) {
            $fail("The {$attribute} field must not be greater than {$this->maxBytes} bytes when encoded.");
        }
    }
}
