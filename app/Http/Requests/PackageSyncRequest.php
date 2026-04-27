<?php

namespace App\Http\Requests;

use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use App\Services\IntervalParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'project' => ['required', 'array'],
            'project.key' => ['required', 'string', 'alpha_dash', 'max:100'],
            'project.name' => ['required', 'string', 'max:255'],
            'project.environment' => ['required', 'string', 'max:255'],
            'project.base_url' => ['required', 'url', 'max:1000'],
            'project.repository' => ['nullable', 'string', 'max:255'],

            'defaults' => ['nullable', 'array'],
            'defaults.headers' => ['nullable', 'array'],
            'defaults.headers.*' => ['nullable', 'string', 'max:2000'],
            'defaults.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],

            'checks' => ['present', 'array', 'max:200'],
            'checks.*.key' => ['required', 'string', 'alpha_dash', 'max:150', 'distinct'],
            'checks.*.type' => ['required', Rule::in(['api', 'ssl', 'uptime', 'links', 'opengraph'])],
            'checks.*.name' => ['required', 'string', 'max:255'],
            'checks.*.method' => ['required_if:checks.*.type,api', 'nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'checks.*.url' => ['required', 'string', 'max:1000'],
            'checks.*.headers' => ['nullable', 'array'],
            'checks.*.headers.*' => ['nullable', 'string', 'max:2000'],
            'checks.*.request_body_type' => ['exclude_unless:checks.*.type,api', new RequestBodyTypeRequired, 'nullable', 'string', Rule::in(['json', 'form', 'raw'])],
            'checks.*.request_body' => ['exclude_unless:checks.*.type,api', 'nullable', new RequestBodyMaxSize, new StructuredRequestBody],
            'checks.*.expected_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'checks.*.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'checks.*.assertions' => ['nullable', 'array', 'max:50'],
            'checks.*.assertions.*.type' => ['required', 'string', Rule::in([
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
            'checks.*.assertions.*.path' => ['required', 'string', 'max:500'],
            'checks.*.assertions.*.expected_value' => ['nullable'],
            'checks.*.assertions.*.expected_type' => ['nullable', 'string', 'max:50'],
            'checks.*.assertions.*.comparison_operator' => ['nullable', Rule::in(['=', '!=', '>', '>=', '<', '<=', 'contains'])],
            'checks.*.assertions.*.regex_pattern' => ['nullable', 'string', 'max:1000'],
            'checks.*.schedule' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail): void {
                $segments = explode('.', $attribute);
                $type = isset($segments[1]) ? $this->input("checks.{$segments[1]}.type") : null;

                if ($type !== 'api') {
                    return;
                }

                if ($value !== null && (! is_string($value) || ! IntervalParser::isValid($value))) {
                    $fail('The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.');
                }
            }],
            'checks.*.enabled' => ['nullable', 'boolean'],
        ];
    }
}
