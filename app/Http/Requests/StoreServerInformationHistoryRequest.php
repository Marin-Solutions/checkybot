<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerInformationHistoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cpu_load' => ['required', 'numeric', 'min:0', 'max:10000'],
            'cpu_cores' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4096'],
            's' => ['required', 'integer', 'min:1'],
            'ram_free_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'ram_free' => ['required', 'integer', 'min:0'],
            'disk_free_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'disk_free_bytes' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages()
    {
        return [
            'cpu_load.required' => __('Required Cpu Load'),
            's.required' => __('Required Server ID'),
            'ram_free_percentage.required' => __('Required Ram free %'),
            'ram_free.required' => __('Required Ram free in bytes'),
            'disk_free_percentage.required' => __('Required disk free %'),
            'disk_free_bytes.required' => __('Required disk free size'),
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'server_id' => $this->s,
        ]);
    }
}
