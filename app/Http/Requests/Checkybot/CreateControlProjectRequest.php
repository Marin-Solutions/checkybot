<?php

namespace App\Http\Requests\Checkybot;

use Illuminate\Foundation\Http\FormRequest;

class CreateControlProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'alpha_dash', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:1000'],
            'repository' => ['nullable', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'technology' => ['nullable', 'string', 'max:255'],
            'identity_endpoint' => ['nullable', 'url', 'max:1000'],
            'package_version' => ['nullable', 'string', 'max:50'],
        ];
    }
}
