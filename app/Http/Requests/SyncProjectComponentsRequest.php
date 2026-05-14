<?php

namespace App\Http\Requests;

use App\Services\IntervalParser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Throwable;

class SyncProjectComponentsRequest extends FormRequest
{
    private const INTERVAL_MESSAGE = 'The interval format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.';

    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project) {
            return false;
        }

        return (bool) $this->user() && $this->user()->id === $project->created_by;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_manifest' => ['sometimes', 'boolean'],

            'declared_components' => ['required_if:full_manifest,true,1', 'array', 'max:100'],
            'declared_components.*.name' => ['required', 'string', 'max:255'],
            'declared_components.*.interval' => ['required', 'string', $this->intervalRule()],

            'components' => ['present', 'array', 'max:100'],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.interval' => ['required', 'string', $this->intervalRule()],
            'components.*.status' => ['required', 'in:healthy,warning,danger'],
            'components.*.summary' => ['nullable', 'string'],
            'components.*.metrics' => ['nullable', 'array'],
            'components.*.observed_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'components.*.observed_at.before_or_equal' => 'The observed timestamp cannot be in the future.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->addDuplicateDeclarationErrors($validator);
            $this->addDuplicateHeartbeatErrors($validator);
        });
    }

    private function intervalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! IntervalParser::isValid($value)) {
                $fail(self::INTERVAL_MESSAGE);
            }
        };
    }

    private function addDuplicateDeclarationErrors(Validator $validator): void
    {
        $declarations = $this->input('declared_components', []);

        if (! is_array($declarations)) {
            return;
        }

        $seenNames = [];

        foreach ($declarations as $index => $declaration) {
            $name = is_array($declaration) ? ($declaration['name'] ?? null) : null;

            if (! is_string($name)) {
                continue;
            }

            if (isset($seenNames[$name])) {
                $validator->errors()->add(
                    "declared_components.{$index}.name",
                    'Each declared component name must be unique.'
                );

                continue;
            }

            $seenNames[$name] = true;
        }
    }

    private function addDuplicateHeartbeatErrors(Validator $validator): void
    {
        $heartbeats = $this->input('components', []);

        if (! is_array($heartbeats)) {
            return;
        }

        $seenHeartbeats = [];

        foreach ($heartbeats as $index => $heartbeat) {
            if (! is_array($heartbeat)) {
                continue;
            }

            $name = $heartbeat['name'] ?? null;
            $observedAt = $heartbeat['observed_at'] ?? null;

            if (! is_string($name) || ! is_string($observedAt)) {
                continue;
            }

            try {
                $identity = $name.'|'.Carbon::parse($observedAt)->toISOString();
            } catch (Throwable) {
                continue;
            }

            if (isset($seenHeartbeats[$identity])) {
                $validator->errors()->add(
                    "components.{$index}.observed_at",
                    'Each component heartbeat observation must be unique by component name and observed_at.'
                );

                continue;
            }

            $seenHeartbeats[$identity] = true;
        }
    }
}
