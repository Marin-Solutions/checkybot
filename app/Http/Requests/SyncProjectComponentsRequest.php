<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncProjectComponentsRequest extends FormRequest
{
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
            'declared_components' => ['required', 'array', 'max:100'],
            'declared_components.*.name' => ['required', 'string', 'max:255'],
            'declared_components.*.interval' => ['required', 'string', 'regex:/^[1-9]\d*[mhd]$/'],

            'components' => ['present', 'array', 'max:100'],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.interval' => ['required', 'string', 'regex:/^[1-9]\d*[mhd]$/'],
            'components.*.status' => ['required', 'in:healthy,warning,danger'],
            'components.*.summary' => ['nullable', 'string'],
            'components.*.metrics' => ['nullable', 'array'],
            'components.*.observed_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'declared_components.*.interval.regex' => 'The interval format is invalid. Use format: {positive number}{m|h|d} (e.g., 5m, 2h, 1d)',
            'components.*.interval.regex' => 'The interval format is invalid. Use format: {positive number}{m|h|d} (e.g., 5m, 2h, 1d)',
        ];
    }
}
