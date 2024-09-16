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
            'cpu_load'=> ['required'],
            's'=> ['required'],
            'ram_free_percentage'=> ['required'],
            'ram_free'=> ['required'],
            'disk_free_percentage'=> ['required'],
            'disk_free_bytes'=> ['required']
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
            'server_id'=> $this->s
        ]);
    }
}
