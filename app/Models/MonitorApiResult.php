<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorApiResult extends Model
{
    protected $fillable = [
        'monitor_api_id',
        'is_success',
        'response_time_ms',
        'http_code',
        'failed_assertions'
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'response_time_ms' => 'integer',
        'http_code' => 'integer',
        'failed_assertions' => 'array'
    ];

    public function monitorApi(): BelongsTo
    {
        return $this->belongsTo(MonitorApis::class, 'monitor_api_id');
    }

    public static function recordResult(MonitorApis $api, array $testResult, float $startTime): self
    {
        // Determine if all assertions passed
        $isSuccess = true;
        $failedAssertions = [];

        if (!empty($testResult['assertions'])) {
            foreach ($testResult['assertions'] as $assertion) {
                if (!$assertion['passed']) {
                    $isSuccess = false;
                    $failedAssertions[] = [
                        'path' => $assertion['path'],
                        'type' => $assertion['type'],
                        'message' => $assertion['message']
                    ];
                }
            }
        }

        // Calculate response time
        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        // Create new result for every request
        return self::create([
            'monitor_api_id' => $api->id,
            'is_success' => $isSuccess,
            'response_time_ms' => $responseTime,
            'http_code' => $testResult['code'],
            'failed_assertions' => $failedAssertions
        ]);
    }
}
