<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class RequestBodyTypeRequired implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

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
        if (filled($value)) {
            return;
        }

        $bodyAttribute = preg_replace('/request_body_type$/', 'request_body', $attribute) ?? '';
        $body = Arr::get($this->data, $bodyAttribute);

        if ($body === null || $body === '') {
            return;
        }

        $fail('The request body type field is required when request body is present.');
    }
}
