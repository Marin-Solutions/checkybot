<?php

namespace App\Http\Requests;

use App\Services\IntervalParser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

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

            'declared_components' => ['present_if:full_manifest,true,1', 'array', 'max:100'],
            'declared_components.*.name' => ['required', 'string', 'max:255'],
            'declared_components.*.interval' => ['required', 'string', $this->intervalRule()],

            'components' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'components.prohibited' => 'Component sync accepts declarations only; runtime heartbeat observations are no longer supported.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->addDuplicateDeclarationErrors($validator);
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

            $normalizedName = $this->normalizedComponentName($name);

            if (isset($seenNames[$normalizedName])) {
                $validator->errors()->add(
                    "declared_components.{$index}.name",
                    'Each declared component name must be unique.'
                );

                continue;
            }

            $seenNames[$normalizedName] = true;
        }
    }

    private function normalizedComponentName(string $name): string
    {
        return Str::lower(trim($name));
    }
}
