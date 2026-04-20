<?php

namespace App\Http\Requests\Checkybot;

use Illuminate\Foundation\Http\FormRequest;

class ListControlRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'project' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
