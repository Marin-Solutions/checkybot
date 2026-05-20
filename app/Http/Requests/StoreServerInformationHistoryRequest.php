<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerInformationHistoryRequest extends FormRequest
{
    private const MAX_CPU_LOAD = 99.99;

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
            'cpu_load' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_CPU_LOAD],
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
            'cpu_load' => $this->normalizeCpuLoad($this->input('cpu_load')),
            'server_id' => $this->s,
        ]);
    }

    private function normalizeCpuLoad(mixed $value): mixed
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $value;
        }

        $normalized = str_replace(' ', '', trim((string) $value));

        $normalized = $this->normalizeDecimalSeparators($normalized);

        if (! is_numeric($normalized)) {
            return $value;
        }

        $cpuLoad = (float) $normalized;

        if (! is_finite($cpuLoad)) {
            return number_format(self::MAX_CPU_LOAD, 2, '.', '');
        }

        if ($cpuLoad > self::MAX_CPU_LOAD) {
            return number_format(self::MAX_CPU_LOAD, 2, '.', '');
        }

        return number_format($cpuLoad, 2, '.', '');
    }

    private function normalizeDecimalSeparators(string $value): string
    {
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                return str_replace(',', '.', str_replace('.', '', $value));
            }

            return str_replace(',', '', $value);
        }

        if ($lastComma !== false) {
            return str_replace(',', '.', $value);
        }

        return $value;
    }
}
