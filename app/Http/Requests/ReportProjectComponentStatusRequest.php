<?php

namespace App\Http\Requests;

use App\Support\ComponentStatusContract;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportProjectComponentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project !== null
            && (bool) $this->user()
            && $this->user()->id === $project->created_by;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ComponentStatusContract::STATUSES)],
            'observed_at' => ['required', 'string'],
            'message' => ['required', 'string', 'max:'.ComponentStatusContract::MAX_MESSAGE_LENGTH],
            'metrics' => [
                'required',
                'array',
                'max:'.ComponentStatusContract::MAX_METRICS,
                'array:'.implode(',', ComponentStatusContract::METRIC_KEYS),
            ],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateIdempotencyKey($validator);
            $this->validateTopLevelKeys($validator);
            $this->validateObservedAt($validator);
            $this->validateMessage($validator);
            $this->validateMetrics($validator);
        });
    }

    private function validateIdempotencyKey(Validator $validator): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $this->idempotencyKey()) !== 1) {
            $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be exactly 64 hexadecimal characters.');
        }
    }

    private function validateTopLevelKeys(Validator $validator): void
    {
        $allowedKeys = ['status', 'observed_at', 'message', 'metrics'];

        foreach (array_diff(array_keys($this->all()), $allowedKeys) as $key) {
            $validator->errors()->add((string) $key, 'This field is not part of the component status contract.');
        }
    }

    private function validateObservedAt(Validator $validator): void
    {
        $observedAt = $this->input('observed_at');

        if (! is_string($observedAt)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)\z/', $observedAt) !== 1
        ) {
            $validator->errors()->add('observed_at', 'Observed at must be RFC3339 UTC.');

            return;
        }

        try {
            $parsed = new DateTimeImmutable($observedAt);
        } catch (\Exception) {
            $validator->errors()->add('observed_at', 'Observed at must be RFC3339 UTC.');

            return;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            $validator->errors()->add('observed_at', 'Observed at must be RFC3339 UTC.');

            return;
        }

        if (Carbon::instance($parsed)->greaterThan(
            Carbon::now('UTC')->addSeconds(ComponentStatusContract::MAX_FUTURE_SKEW_SECONDS)
        )) {
            $validator->errors()->add('observed_at', 'Observed at cannot be more than 120 seconds in the future.');
        }
    }

    private function validateMessage(Validator $validator): void
    {
        $message = $this->input('message');

        if (! is_string($message)
            || $message === ''
            || strlen($message) > ComponentStatusContract::MAX_MESSAGE_LENGTH
            || preg_match('/\A[^\x00-\x1F\x7F]+\z/u', $message) !== 1
        ) {
            $validator->errors()->add('message', 'Message must be short, non-empty, and free of control characters.');
        }
    }

    private function validateMetrics(Validator $validator): void
    {
        $metrics = $this->input('metrics');

        if (! is_array($metrics)) {
            return;
        }

        foreach ($metrics as $key => $value) {
            if (! is_string($key) || ! in_array($key, ComponentStatusContract::METRIC_KEYS, true)) {
                $validator->errors()->add("metrics.{$key}", 'This metric key is not allowed.');

                continue;
            }

            if (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                $validator->errors()->add("metrics.{$key}", 'Metric values must be integers, finite numbers, or booleans.');

                continue;
            }

            if (is_float($value) && ! is_finite($value)) {
                $validator->errors()->add("metrics.{$key}", 'Metric values must be finite.');

                continue;
            }

            if (! is_bool($value) && ($value < 0 || $value > ComponentStatusContract::MAX_METRIC_VALUE)) {
                $validator->errors()->add("metrics.{$key}", 'Metric values are outside the supported bounds.');
            }
        }
    }
}
