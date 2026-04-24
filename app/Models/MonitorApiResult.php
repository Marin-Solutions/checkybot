<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorApiResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitor_api_id',
        'is_success',
        'response_time_ms',
        'http_code',
        'failed_assertions',
        'response_body',
        'status',
        'summary',
        'request_headers',
        'response_headers',
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'response_time_ms' => 'integer',
        'http_code' => 'integer',
        'failed_assertions' => 'array',
        'request_headers' => 'array',
        'response_headers' => 'array',
    ];

    protected function responseBody(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): mixed => static::decodeJsonAttribute($value),
            set: fn (mixed $value): ?string => static::encodeJsonAttribute($value),
        );
    }

    public function monitorApi(): BelongsTo
    {
        return $this->belongsTo(MonitorApis::class, 'monitor_api_id');
    }

    public static function recordResult(MonitorApis $api, array $testResult, float $startTime, ?string $status = null, ?string $summary = null): self
    {
        $isSuccess = $status === null ? true : $status === 'healthy';
        $failedAssertions = [];

        if ($status === null && isset($testResult['code'])) {
            $code = $testResult['code'];
            if ($code === 0 || $code >= 400) {
                $isSuccess = false;
            }
        }

        if (! empty($testResult['assertions'])) {
            foreach ($testResult['assertions'] as $assertion) {
                if (! $assertion['passed']) {
                    $isSuccess = false;
                    $failedAssertions[] = [
                        'path' => $assertion['path'] ?? null,
                        'type' => $assertion['type'] ?? null,
                        'message' => $assertion['message'] ?? 'Assertion failed',
                    ];
                }
            }
        }

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        $savedResponseBody = static::prepareSavedResponseBody($api, $isSuccess, $testResult);

        return self::create([
            'monitor_api_id' => $api->id,
            'is_success' => $isSuccess,
            'response_time_ms' => $responseTime,
            'http_code' => $testResult['code'],
            'failed_assertions' => $failedAssertions,
            'response_body' => $savedResponseBody,
            'status' => $status,
            'summary' => $summary,
            'request_headers' => $testResult['request_headers'] ?? null,
            'response_headers' => $testResult['response_headers'] ?? null,
        ]);
    }

    private static function prepareSavedResponseBody(MonitorApis $api, bool $isSuccess, array $testResult): ?array
    {
        if ($isSuccess || ! $api->save_failed_response) {
            return null;
        }

        $payload = [];

        if (is_array($testResult['body'] ?? null)) {
            $payload = $testResult['body'];
        } elseif (filled($testResult['raw_body'] ?? null)) {
            $payload['raw_body'] = (string) $testResult['raw_body'];
        } elseif (filled($testResult['body'] ?? null)) {
            $payload['raw_body'] = (string) $testResult['body'];
        }

        if (filled($testResult['error'] ?? null)) {
            $payload['error'] = (string) $testResult['error'];
        }

        return $payload === [] ? null : $payload;
    }

    private static function decodeJsonAttribute(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private static function encodeJsonAttribute(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
    }
}
