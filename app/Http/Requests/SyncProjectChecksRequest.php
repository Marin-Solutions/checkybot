<?php

namespace App\Http\Requests;

use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use Illuminate\Foundation\Http\FormRequest;

class SyncProjectChecksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project) {
            return false;
        }

        return $this->user() && $this->user()->id === $project->created_by;
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('api_checks'))) {
            return;
        }

        $this->merge([
            'api_checks' => array_map(function (mixed $check): mixed {
                if (is_array($check) && isset($check['method']) && is_string($check['method'])) {
                    $check['method'] = strtoupper($check['method']);
                }

                return $check;
            }, $this->input('api_checks')),
        ]);
    }

    public function rules(): array
    {
        return [
            'uptime_checks' => ['array', 'max:100'],
            'uptime_checks.*.name' => $this->checkNameRules(),
            'uptime_checks.*.url' => ['required', 'url', 'max:1000'],
            'uptime_checks.*.interval' => ['required', 'string', 'regex:/^[1-9]\d*[mhd]$/'],
            'uptime_checks.*.max_redirects' => ['integer', 'min:0', 'max:20'],

            'ssl_checks' => ['array', 'max:100'],
            'ssl_checks.*.name' => $this->checkNameRules(),
            'ssl_checks.*.url' => ['required', 'url', 'max:1000'],
            'ssl_checks.*.interval' => ['required', 'string', 'regex:/^[1-9]\d*[mhd]$/'],

            'api_checks' => ['array', 'max:100'],
            'api_checks.*.name' => $this->checkNameRules(),
            'api_checks.*.url' => ['required', 'url', 'max:1000'],
            'api_checks.*.interval' => ['required', 'string', 'regex:/^[1-9]\d*[mhd]$/'],
            'api_checks.*.method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'api_checks.*.headers' => ['array'],
            'api_checks.*.request_body_type' => [new RequestBodyTypeRequired, 'nullable', 'in:json,form,raw'],
            'api_checks.*.request_body' => ['nullable', new RequestBodyMaxSize, new StructuredRequestBody],
            'api_checks.*.expected_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'api_checks.*.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'api_checks.*.save_failed_response' => ['nullable', 'boolean'],
            'api_checks.*.enabled' => ['boolean'],
            'api_checks.*.assertions' => ['array'],
            'api_checks.*.assertions.*.data_path' => ['required', 'string'],
            'api_checks.*.assertions.*.assertion_type' => ['required', 'in:exists,not_exists,type_check,value_compare,array_length,regex_match'],
            'api_checks.*.assertions.*.expected_type' => ['nullable', 'string'],
            'api_checks.*.assertions.*.comparison_operator' => ['nullable', 'in:=,!=,>,>=,<,<=,contains'],
            'api_checks.*.assertions.*.expected_value' => ['nullable', 'string'],
            'api_checks.*.assertions.*.regex_pattern' => ['nullable', 'string'],
            'api_checks.*.assertions.*.sort_order' => ['integer', 'min:1'],
            'api_checks.*.assertions.*.is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'uptime_checks.*.interval.regex' => 'The interval format is invalid. Use format: {positive number}{m|h|d} (e.g., 5m, 2h, 1d)',
            'ssl_checks.*.interval.regex' => 'The interval format is invalid. Use format: {positive number}{m|h|d} (e.g., 5m, 2h, 1d)',
            'api_checks.*.interval.regex' => 'The interval format is invalid. Use format: {positive number}{m|h|d} (e.g., 5m, 2h, 1d)',
            'uptime_checks.*.name.not_regex' => 'Check names cannot contain "/" because they are used as URL path keys.',
            'ssl_checks.*.name.not_regex' => 'Check names cannot contain "/" because they are used as URL path keys.',
            'api_checks.*.name.not_regex' => 'Check names cannot contain "/" because they are used as URL path keys.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function checkNameRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:#/#'];
    }
}
