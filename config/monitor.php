<?php

return [
    'api_timeout' => env('MONITOR_API_TIMEOUT', 30),
    'api_retries' => env('MONITOR_API_RETRIES', 3),
    'api_retry_delay' => env('MONITOR_API_RETRY_DELAY', 1000),
];
