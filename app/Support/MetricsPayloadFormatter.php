<?php

namespace App\Support;

class MetricsPayloadFormatter
{
    /**
     * @param  array<string, mixed>|null  $metrics
     */
    public static function format(?array $metrics): string
    {
        if (blank($metrics)) {
            return '{}';
        }

        return json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
