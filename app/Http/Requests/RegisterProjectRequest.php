<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'app_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:255'],
            'identity_endpoint' => ['required', 'url', 'max:1000'],
            'technology' => ['nullable', 'string', 'max:255'],
            'package_version' => ['nullable', 'string', 'max:50'],
        ];
    }
}
