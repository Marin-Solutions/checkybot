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
            'name.required' => __('Required Name'),
            'url.required' => __('Required URL'),
            'url.url' => __('not valid URL, check if include https:// or http://'),
            'description.required' => __('Required'),
            'created_by.required' => __('Required'),
            'uptime_check.required' => __('Required'),
            'uptime_interval.required' => __('Required'),
        ];
    }

}
