<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Rules\RelativeOrHttpUrl;
use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use App\Services\IntervalParser;
use App\Support\ValidatesMonitorApiRegexAssertions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SyncProjectChecksRequest extends FormRequest
{
    use ValidatesMonitorApiRegexAssertions;

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
        $payload = [];

        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $checkGroup) {
            if (! is_array($this->input($checkGroup))) {
                continue;
            }

            $payload[$checkGroup] = array_map(function (mixed $check): mixed {
                if (is_array($check) && isset($check['method']) && is_string($check['method'])) {
                    $check['method'] = strtoupper($check['method']);
                }

                if (is_array($check) && isset($check['url']) && is_string($check['url'])) {
                    $check['url'] = trim($check['url']);
                }

                if (is_array($check) && isset($check['component']) && is_string($check['component'])) {
                    $check['component'] = trim($check['component']);
                }

                return $check;
            }, $this->input($checkGroup));
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        return [
            'link_checks' => ['missing'],
            'open_graph_checks' => ['missing'],

            'uptime_checks' => ['array', 'max:100'],
            'uptime_checks.*.last_heartbeat_at' => ['prohibited'],
            'uptime_checks.*.awaiting_heartbeat_since' => ['prohibited'],
            'uptime_checks.*.stale_at' => ['prohibited'],
            'uptime_checks.*.stale_detected_at' => ['prohibited'],
            'uptime_checks.*.stale_threshold_at' => ['prohibited'],
            'uptime_checks.*.is_stale' => ['prohibited'],
            'uptime_checks.*.status' => ['prohibited'],
            'uptime_checks.*.current_status' => ['prohibited'],
            'uptime_checks.*.status_summary' => ['prohibited'],
            'uptime_checks.*.metrics' => ['prohibited'],
            'uptime_checks.*.observed_at' => ['prohibited'],
            'uptime_checks.*.summary' => ['prohibited'],
            'uptime_checks.*.name' => $this->checkNameRules(),
            'uptime_checks.*.url' => ['required', 'string', 'max:1000', new RelativeOrHttpUrl],
            'uptime_checks.*.interval' => $this->intervalRules(),
            'uptime_checks.*.max_redirects' => ['integer', 'min:0', 'max:20'],
            'uptime_checks.*.enabled' => ['nullable', 'boolean'],
            'uptime_checks.*.component' => ['nullable', 'string', 'max:255'],

            'ssl_checks' => ['array', 'max:100'],
            'ssl_checks.*.last_heartbeat_at' => ['prohibited'],
            'ssl_checks.*.awaiting_heartbeat_since' => ['prohibited'],
            'ssl_checks.*.stale_at' => ['prohibited'],
            'ssl_checks.*.stale_detected_at' => ['prohibited'],
            'ssl_checks.*.stale_threshold_at' => ['prohibited'],
            'ssl_checks.*.is_stale' => ['prohibited'],
            'ssl_checks.*.status' => ['prohibited'],
            'ssl_checks.*.current_status' => ['prohibited'],
            'ssl_checks.*.status_summary' => ['prohibited'],
            'ssl_checks.*.metrics' => ['prohibited'],
            'ssl_checks.*.observed_at' => ['prohibited'],
            'ssl_checks.*.summary' => ['prohibited'],
            'ssl_checks.*.name' => $this->checkNameRules(),
            'ssl_checks.*.url' => ['required', 'string', 'max:1000', new RelativeOrHttpUrl],
            'ssl_checks.*.interval' => $this->intervalRules(),
            'ssl_checks.*.enabled' => ['nullable', 'boolean'],
            'ssl_checks.*.component' => ['nullable', 'string', 'max:255'],

            'api_checks' => ['array', 'max:100'],
            'api_checks.*.last_heartbeat_at' => ['prohibited'],
            'api_checks.*.awaiting_heartbeat_since' => ['prohibited'],
            'api_checks.*.stale_at' => ['prohibited'],
            'api_checks.*.stale_detected_at' => ['prohibited'],
            'api_checks.*.stale_threshold_at' => ['prohibited'],
            'api_checks.*.is_stale' => ['prohibited'],
            'api_checks.*.status' => ['prohibited'],
            'api_checks.*.current_status' => ['prohibited'],
            'api_checks.*.status_summary' => ['prohibited'],
            'api_checks.*.metrics' => ['prohibited'],
            'api_checks.*.observed_at' => ['prohibited'],
            'api_checks.*.summary' => ['prohibited'],
            'api_checks.*.name' => $this->checkNameRules(),
            'api_checks.*.url' => ['required', 'string', 'max:1000', new RelativeOrHttpUrl],
            'api_checks.*.interval' => $this->intervalRules(),
            'api_checks.*.method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'api_checks.*.headers' => ['array'],
            'api_checks.*.request_body_type' => [new RequestBodyTypeRequired, 'nullable', 'in:json,form,raw'],
            'api_checks.*.request_body' => ['nullable', new RequestBodyMaxSize, new StructuredRequestBody],
            'api_checks.*.expected_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'api_checks.*.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'api_checks.*.save_failed_response' => ['nullable', 'boolean'],
            'api_checks.*.enabled' => ['boolean'],
            'api_checks.*.component' => ['nullable', 'string', 'max:255'],
            'api_checks.*.assertions' => ['array'],
            'api_checks.*.assertions.*.data_path' => ['required', 'string'],
            'api_checks.*.assertions.*.assertion_type' => ['required', 'in:exists,not_exists,type_check,value_compare,array_length,regex_match'],
            'api_checks.*.assertions.*.expected_type' => ['nullable', 'string'],
            'api_checks.*.assertions.*.comparison_operator' => ['nullable', 'in:=,!=,>,>=,<,<=,contains'],
            'api_checks.*.assertions.*.expected_value' => ['nullable'],
            'api_checks.*.assertions.*.regex_pattern' => ['nullable', 'string'],
            'api_checks.*.assertions.*.sort_order' => ['integer', 'min:1'],
            'api_checks.*.assertions.*.is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'link_checks.missing' => 'link_checks are not supported by project check sync yet.',
            'open_graph_checks.missing' => 'open_graph_checks are not supported by project check sync yet.',
            'uptime_checks.*.name.not_regex' => 'Check names cannot contain "/" because they are used as URL path keys.',
            'ssl_checks.*.name.not_regex' => 'Check names cannot contain "/" because they are used as URL path keys.',
            'api_checks.*.name.not_regex' => 'Check names cannot contain "/" because they are used as URL path keys.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAnySyncChecks()) {
                $validator->errors()->add(
                    'checks',
                    'At least one uptime, SSL, or API check is required for project check sync.'
                );

                return;
            }

            $apiChecks = $this->input('api_checks', []);

            if (! is_array($apiChecks)) {
                return;
            }

            foreach ($apiChecks as $checkIndex => $check) {
                if (! is_array($check) || ! is_array($check['assertions'] ?? null)) {
                    continue;
                }

                $this->addExpectedValueShapeValidationErrors(
                    $validator,
                    $check['assertions'],
                    "api_checks.{$checkIndex}.assertions"
                );

                $this->addRegexAssertionValidationErrors(
                    $validator,
                    $check['assertions'],
                    "api_checks.{$checkIndex}.assertions",
                    'assertion_type'
                );
            }

            $project = $this->route('project');

            if ($project instanceof Project) {
                $this->addComponentReferenceValidationErrors($validator, $project);
            }

            if (! $project || filled($project->base_url)) {
                return;
            }

            foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $checkGroup) {
                $checks = $this->input($checkGroup, []);

                if (! is_array($checks)) {
                    continue;
                }

                foreach ($checks as $checkIndex => $check) {
                    $url = is_array($check) ? ($check['url'] ?? null) : null;

                    if (is_string($url) && $this->isRelativeCheckUrl($url)) {
                        $validator->errors()->add(
                            "{$checkGroup}.{$checkIndex}.url",
                            'Relative check URLs require the project to have a base_url. Provide an absolute URL or set the project base_url.'
                        );
                    }
                }
            }
        });
    }

    private function addComponentReferenceValidationErrors(Validator $validator, Project $project): void
    {
        $componentNames = array_flip($project->activeComponents()->pluck('name')->all());

        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $checkGroup) {
            $checks = $this->input($checkGroup, []);

            if (! is_array($checks)) {
                continue;
            }

            foreach ($checks as $checkIndex => $check) {
                $component = is_array($check) ? ($check['component'] ?? null) : null;

                if (! is_string($component) || blank($component) || isset($componentNames[$component])) {
                    continue;
                }

                $validator->errors()->add(
                    "{$checkGroup}.{$checkIndex}.component",
                    "The component \"{$component}\" has not been declared for this project. Sync it through declared_components or fix the component name."
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function checkNameRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:#/#'];
    }

    /**
     * @return array<int, mixed>
     */
    private function intervalRules(): array
    {
        return [
            'required',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! IntervalParser::isValid($value)) {
                    $fail('The interval format is invalid. Use format: {positive number}{s|m|h|d} or every_{positive number}_{seconds|minutes|hours|days} (e.g., 30s, 5m, every_5_minutes).');
                }
            },
        ];
    }

    private function isRelativeCheckUrl(string $url): bool
    {
        return preg_match('/^https?:\/\//i', $url) !== 1;
    }

    private function hasAnySyncChecks(): bool
    {
        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $checkGroup) {
            $checks = $this->input($checkGroup, []);

            if (is_array($checks) && count($checks) > 0) {
                return true;
            }
        }

        return false;
    }
}
