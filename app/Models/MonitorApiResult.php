<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'response_time_ms' => 'integer',
        'http_code' => 'integer',
        'failed_assertions' => 'array',
        'response_body' => 'array',
    ];

    public function monitorApi(): BelongsTo
    {
        return $this->belongsTo(MonitorApis::class, 'monitor_api_id');
    }

    public static function recordResult(MonitorApis $api, array $testResult, float $startTime): self
    {
        // Determine if all assertions passed and HTTP code is successful
        $isSuccess = true;
        $failedAssertions = [];

        // Check if HTTP code indicates failure
        if (isset($testResult['code']) && $testResult['code'] >= 400) {
            $isSuccess = false;
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

        // Calculate response time
        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        // Only save the response body if there was an error and the setting is enabled
        $savedResponseBody = ($isSuccess || ! $api->save_failed_response) ? null : $testResult['body'];

        // Create new result for every request
        return self::create([
            'monitor_api_id' => $api->id,
            'is_success' => $isSuccess,
            'response_time_ms' => $responseTime,
            'http_code' => $testResult['code'],
            'failed_assertions' => $failedAssertions,
            'response_body' => $savedResponseBody,
        ]);
    }
}
