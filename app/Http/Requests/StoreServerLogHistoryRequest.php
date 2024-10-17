<?php

    namespace App\Http\Requests;

    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rules\File;

    class StoreServerLogHistoryRequest extends FormRequest
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
                'log' => [
                    'required',
                    'file',
                    'mimetypes:text/plain',
                    'max:2048',
                ],
                'li'  => [ 'required' ],
            ];
        }

        public function messages()
        {
            return [
                'log.required' => 'Required Log file',
                'li.required'  => 'Required Server ID',
            ];
        }

        protected function prepareForValidation()
        {
            $this->merge([
                'log_category_id' => $this->li
            ]);
        }
    }
