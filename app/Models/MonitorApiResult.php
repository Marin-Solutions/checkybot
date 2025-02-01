<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorApiResult extends Model
{
    protected $fillable = [
        'monitor_api_id',
        'is_success',
        'consecutive_count',
        'response_time_ms',
        'http_code',
        'failed_assertions'
    ];

    protected $casts = [
        'is_success' => 'boolean',
        'consecutive_count' => 'integer',
        'response_time_ms' => 'integer',
        'http_code' => 'integer',
        'failed_assertions' => 'array'
    ];

    public function monitorApi(): BelongsTo
    {
        return $this->belongsTo(MonitorApis::class, 'monitor_api_id');
    }

    public static function recordResult(MonitorApis $api, array $testResult): self
    {
        $startTime = microtime(true);

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

        // Get the last result for this API
        $lastResult = self::where('monitor_api_id', $api->id)
            ->latest()
            ->first();

        // Determine consecutive count
        $consecutiveCount = 1;
        if ($lastResult && $lastResult->is_success === $isSuccess) {
            $consecutiveCount = $lastResult->consecutive_count + 1;

            // Update the last result instead of creating a new one
            $lastResult->update(['consecutive_count' => $consecutiveCount]);
            return $lastResult;
        }

        // Create new result only if status changed or this is the first result
        return self::create([
            'monitor_api_id' => $api->id,
            'is_success' => $isSuccess,
            'consecutive_count' => $consecutiveCount,
            'response_time_ms' => $responseTime,
            'http_code' => $testResult['code'],
            'failed_assertions' => $failedAssertions
        ]);
    }
}
