<?php

namespace App\Http\Requests;

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

    public function rules(): array
    {
        return [
            'uptime_checks' => ['array', 'max:100'],
            'uptime_checks.*.name' => ['required', 'string', 'max:255'],
            'uptime_checks.*.url' => ['required', 'url', 'max:1000'],
            'uptime_checks.*.interval' => ['required', 'string', 'regex:/^\d+[mhd]$/'],
            'uptime_checks.*.max_redirects' => ['integer', 'min:0', 'max:20'],

            'ssl_checks' => ['array', 'max:100'],
            'ssl_checks.*.name' => ['required', 'string', 'max:255'],
            'ssl_checks.*.url' => ['required', 'url', 'max:1000'],
            'ssl_checks.*.interval' => ['required', 'string', 'regex:/^\d+[mhd]$/'],

            'api_checks' => ['array', 'max:100'],
            'api_checks.*.name' => ['required', 'string', 'max:255'],
            'api_checks.*.url' => ['required', 'url', 'max:1000'],
            'api_checks.*.interval' => ['required', 'string', 'regex:/^\d+[mhd]$/'],
            'api_checks.*.headers' => ['array'],
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
            'uptime_checks.*.interval.regex' => 'The interval format is invalid. Use format: {number}{m|h|d} (e.g., 5m, 2h, 1d)',
            'ssl_checks.*.interval.regex' => 'The interval format is invalid. Use format: {number}{m|h|d} (e.g., 5m, 2h, 1d)',
            'api_checks.*.interval.regex' => 'The interval format is invalid. Use format: {number}{m|h|d} (e.g., 5m, 2h, 1d)',
        ];
    }
}
