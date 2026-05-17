<?php

return [
    'api_timeout' => env('MONITOR_API_TIMEOUT', 30),
    'api_retries' => env('MONITOR_API_RETRIES', 3),
    'api_retry_delay' => env('MONITOR_API_RETRY_DELAY', 1000),
    'api_scheduled_timeout' => env('MONITOR_API_SCHEDULED_TIMEOUT', 90),
    'api_scheduled_retries' => env('MONITOR_API_SCHEDULED_RETRIES', 3),
    'api_interactive_timeout' => env('MONITOR_API_INTERACTIVE_TIMEOUT', 5),
    'api_interactive_retries' => env('MONITOR_API_INTERACTIVE_RETRIES', 0),
    'package_sync_stale_minutes' => env('MONITOR_PACKAGE_SYNC_STALE_MINUTES', 15),
    'project_component_stale_grace_minutes' => env('MONITOR_PROJECT_COMPONENT_STALE_GRACE_MINUTES', 1),
    'project_component_stale_chunk_size' => env('MONITOR_PROJECT_COMPONENT_STALE_CHUNK_SIZE', 500),
    'server_log_file_retention_days' => env('MONITOR_SERVER_LOG_FILE_RETENTION_DAYS', 30),
];
