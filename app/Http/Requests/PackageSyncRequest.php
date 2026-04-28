<?php

namespace App\Http\Requests;

use App\Console\Commands\LogJobCheckUptimeSsl;
use App\Rules\RequestBodyMaxSize;
use App\Rules\RequestBodyTypeRequired;
use App\Rules\StructuredRequestBody;
use App\Services\IntervalParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'checks.*.key' => ['required', 'string', 'alpha_dash', 'max:150'],
            'checks.*.type' => ['required', Rule::in(['api', 'ssl', 'uptime'])],
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

                if (! in_array($type, ['api', 'ssl', 'uptime'], true)) {
                    return;
                }

                if (! is_string($value) || ! IntervalParser::isValid($value)) {
                    $fail('The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.');
                }
            }],
            'checks.*.enabled' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $checks = $this->input('checks', []);

            if (! is_array($checks)) {
                return;
            }

            $keysByType = [];
            $typesByKey = [];
            $indexesByKey = [];
            $urlByWebsiteKey = [];
            $nameByWebsiteKey = [];

            foreach ($checks as $index => $check) {
                if (! is_array($check)) {
                    continue;
                }

                $type = $check['type'] ?? null;
                $key = $check['key'] ?? null;

                if (is_string($type) && is_string($key)) {
                    if (isset($keysByType[$type][$key])) {
                        $validator->errors()->add(
                            "checks.{$index}.key",
                            "The checks.{$index}.key field has a duplicate value."
                        );
                    }

                    $keysByType[$type][$key] = true;
                    $typesByKey[$key][$type] = true;
                    $indexesByKey[$key][] = $index;
                }

                if (! in_array($type, ['ssl', 'uptime'], true)) {
                    continue;
                }

                $url = $check['url'] ?? null;
                $name = $check['name'] ?? null;

                if (is_string($key) && is_string($url)) {
                    $normalizedUrl = $this->resolveUrl($this->input('project.base_url'), $url);

                    if (isset($urlByWebsiteKey[$key]) && $urlByWebsiteKey[$key] !== $normalizedUrl) {
                        $validator->errors()->add(
                            "checks.{$index}.url",
                            'Uptime and SSL checks that share a key must have the same URL.'
                        );
                    }

                    $urlByWebsiteKey[$key] = $normalizedUrl;
                }

                if (is_string($key) && is_string($name)) {
                    if (isset($nameByWebsiteKey[$key]) && $nameByWebsiteKey[$key] !== $name) {
                        $validator->errors()->add(
                            "checks.{$index}.name",
                            'Uptime and SSL checks that share a key must have the same name.'
                        );
                    }

                    $nameByWebsiteKey[$key] = $name;
                }

                if (! array_key_exists('schedule', $check) || $check['schedule'] === null) {
                    $validator->errors()->add(
                        "checks.{$index}.schedule",
                        'The schedule field is required for uptime and SSL checks.'
                    );
                }

                if (in_array($type, ['ssl', 'uptime'], true) && is_string($check['schedule'] ?? null) && IntervalParser::isValid($check['schedule'])) {
                    $normalizedSchedule = IntervalParser::normalize($check['schedule']);

                    if (Str::endsWith($normalizedSchedule, 's')) {
                        $validator->errors()->add(
                            "checks.{$index}.schedule",
                            'Uptime and SSL schedules cannot be specified in seconds. Supported values: 1m, 5m, 10m, 15m, 30m, 1h, 6h, 12h, 1d.'
                        );
                    } elseif ($type === 'uptime' && ! in_array(IntervalParser::toMinutes($normalizedSchedule), LogJobCheckUptimeSsl::SUPPORTED_INTERVALS, true)) {
                        $validator->errors()->add(
                            "checks.{$index}.schedule",
                            'Unsupported uptime interval. Supported values: 1m, 5m, 10m, 15m, 30m, 1h, 6h, 12h, 1d.'
                        );
                    }
                }
            }

            foreach ($typesByKey as $key => $types) {
                $typeNames = array_keys($types);
                sort($typeNames);

                if ($typeNames === ['ssl', 'uptime']) {
                    continue;
                }

                if (count($typeNames) <= 1) {
                    continue;
                }

                foreach (array_slice($indexesByKey[$key] ?? [], 1) as $index) {
                    $validator->errors()->add(
                        "checks.{$index}.key",
                        'Check keys must be unique across the package payload, except when uptime and SSL checks share the same key.'
                    );
                }
            }
        });
    }

    private function resolveUrl(mixed $baseUrl, string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return rtrim(is_string($baseUrl) ? $baseUrl : '', '/').'/'.ltrim($url, '/');
    }
}
