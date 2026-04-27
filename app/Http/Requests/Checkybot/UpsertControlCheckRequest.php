<?php

namespace App\Http\Requests\Checkybot;

use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use App\Services\IntervalParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertControlCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['api'])],
            'name' => ['required', 'string', 'max:255'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'url' => ['required', 'string', 'max:1000'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:2000'],
            'request_body_type' => [new RequestBodyTypeRequired, 'nullable', 'string', Rule::in(['json', 'form', 'raw'])],
            'request_body' => ['nullable', new RequestBodyMaxSize, new StructuredRequestBody],
            'expected_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'assertions' => ['nullable', 'array', 'max:50'],
            'assertions.*.type' => ['required', 'string', Rule::in([
                'json_path_exists',
                'json_path_not_exists',
                'json_path_equals',
                'exists',
                'not_exists',
                'value_compare',
                'type_check',
                'array_length',
                'regex_match',
            ])],
            'assertions.*.path' => ['required', 'string', 'max:500'],
            'assertions.*.expected_value' => ['nullable'],
            'assertions.*.expected_type' => ['nullable', 'string', 'max:50'],
            'assertions.*.comparison_operator' => ['nullable', Rule::in(['=', '!=', '>', '>=', '<', '<=', 'contains'])],
            'assertions.*.regex_pattern' => ['nullable', 'string', 'max:1000'],
            'assertions.*.sort_order' => ['nullable', 'integer', 'min:1'],
            'assertions.*.active' => ['nullable', 'boolean'],
            'schedule' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && (! is_string($value) || ! IntervalParser::isValid($value))) {
                    $fail('The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.');
                }
            }],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
