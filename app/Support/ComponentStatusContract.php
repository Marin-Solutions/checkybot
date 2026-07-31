<?php

namespace App\Support;

final class ComponentStatusContract
{
    public const MAX_MESSAGE_LENGTH = 500;

    public const MAX_METRICS = 20;

    public const MAX_METRIC_VALUE = 1_000_000_000;

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        'healthy',
        'warning',
        'failure',
    ];

    /**
     * @var array<int, string>
     */
    public const METRIC_KEYS = [
        'active',
        'configured_pairs',
        'count',
        'coverage_percent',
        'due',
        'duration_ms',
        'error_count',
        'failed',
        'failure_count',
        'failure_streak',
        'healthy',
        'latency_ms',
        'missing_pairs',
        'oldest_overdue_age_minutes',
        'overdue',
        'stale_claims',
        'success_count',
        'total',
        'unique_keywords',
        'warning',
    ];

    public static function storedStatus(string $status): string
    {
        return $status === 'failure' ? 'danger' : $status;
    }
}
