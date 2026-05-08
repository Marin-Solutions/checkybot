<?php

namespace App\Http\Requests\Checkybot;

use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use App\Services\IntervalParser;
use App\Support\ValidatesMonitorApiRegexAssertions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertControlCheckRequest extends FormRequest
{
    use ValidatesMonitorApiRegexAssertions;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $schedule = $this->input('schedule');

        if ($this->has('schedule') && ($schedule === null || (is_string($schedule) && blank($schedule)))) {
            $this->merge(['schedule' => IntervalParser::DEFAULT_API_INTERVAL]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['api'])],
            'name' => ['required', 'string', 'max:255'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'url' => ['required', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! $this->isValidControlCheckUrl($value)) {
                    $fail('The url must be an HTTP(S) URL or a valid relative path.');
                }
            }],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assertions = $this->input('assertions', []);

            if (! is_array($assertions)) {
                return;
            }

            $this->addExpectedValueShapeValidationErrors(
                $validator,
                $assertions,
                'assertions'
            );

            $this->addRegexAssertionValidationErrors($validator, $assertions, 'assertions');
        });
    }

    private function isValidControlCheckUrl(string $url): bool
    {
        if ($url === '' || trim($url) !== $url || preg_match('/\s/', $url) === 1) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            return in_array($scheme, ['http', 'https'], true);
        }

        if (
            str_contains($url, '://')
            || str_starts_with($url, '//')
            || str_starts_with($url, '#')
            || preg_match('/^https?\/\//i', $url) === 1
        ) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return false;
        }

        return parse_url($url) !== false;
    }
}
