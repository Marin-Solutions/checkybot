<?php

namespace App\Http\Requests;

use Illuminate\Http\JsonResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreWebsiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'=> ['required'],
            'url'=> ['required','url'],
            'description'=> ['required'],
            'created_by'=> ['required'],
            'uptime_check'=> ['required'],
            'uptime_interval'=> ['required']
        ];
    }


    public function messages()
    {
        return [
            'name.required' => 'Required Name',
            'url.required' => 'Required URL',
            'url.url' => 'not valid URL',
            'description.required' => 'Required',
            'created_by.required' => 'Required',
            'uptime_check.required' => 'Required',
            'uptime_interval.required' => 'Required',
        ];
    }


}
