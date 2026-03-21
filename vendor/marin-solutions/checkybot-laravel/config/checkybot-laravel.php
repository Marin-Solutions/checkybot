<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Checkybot API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Checkybot instance URL and authentication credentials.
    | Package traffic authenticates with your account API key and resolves the
    | target application through the registration handshake.
    |
    */

    'api_key' => env('CHECKYBOT_API_KEY'),
    'base_url' => env('CHECKYBOT_URL', 'https://checkybot.com'),
    'project_id' => env('CHECKYBOT_PROJECT_ID'),
    'app_id' => env('CHECKYBOT_APP_ID'),
    'application_name' => env('CHECKYBOT_APPLICATION_NAME', config('app.name')),
    'environment' => env('CHECKYBOT_ENVIRONMENT', config('app.env')),
    'identity_endpoint' => env('CHECKYBOT_IDENTITY_ENDPOINT', config('app.url')),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    */

    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 1000, // milliseconds

    /*
    |--------------------------------------------------------------------------
    | Monitoring Checks
    |--------------------------------------------------------------------------
    |
    | Define your monitoring checks below. Each check must have a unique name
    | within its type (uptime, ssl, api). Names are used to identify checks
    | during sync operations.
    |
    */

    'checks' => [

        /*
        |--------------------------------------------------------------------------
        | Uptime Checks
        |--------------------------------------------------------------------------
        |
        | Monitor website uptime and response times.
        |
        | Required fields:
        |   - name: Unique identifier for this check
        |   - url: Full URL to monitor
        |   - interval: How often to check (format: {number}{m|h|d})
        |
        | Optional fields:
        |   - max_redirects: Maximum redirects to follow (default: 10)
        |
        */

        'uptime' => [
            // [
            //     'name' => 'homepage-uptime',
            //     'url' => env('APP_URL'),
            //     'interval' => '5m',
            //     'max_redirects' => 10,
            // ],
        ],

        /*
        |--------------------------------------------------------------------------
        | SSL Certificate Checks
        |--------------------------------------------------------------------------
        |
        | Monitor SSL certificate expiration.
        |
        | Required fields:
        |   - name: Unique identifier for this check
        |   - url: Full URL to check SSL certificate
        |   - interval: How often to check (typically '1d' for daily)
        |
        */

        'ssl' => [
            // [
            //     'name' => 'homepage-ssl',
            //     'url' => env('APP_URL'),
            //     'interval' => '1d',
            // ],
        ],

        /*
        |--------------------------------------------------------------------------
        | API Endpoint Checks
        |--------------------------------------------------------------------------
        |
        | Monitor API endpoints and validate JSON responses.
        |
        | Required fields:
        |   - name: Unique identifier for this check
        |   - url: Full API endpoint URL
        |   - interval: How often to check
        |
        | Optional fields:
        |   - headers: Array of HTTP headers to send
        |   - assertions: Array of validation rules for the response
        |
        | Assertion Types:
        |   - exists: Check if a JSON path exists
        |   - type: Check if value matches expected type
        |   - comparison: Compare value using operator
        |   - regex: Match value against regex pattern
        |
        */

        'api' => [
            // [
            //     'name' => 'health-check',
            //     'url' => env('APP_URL').'/api/health',
            //     'interval' => '5m',
            //     'headers' => [
            //         'Accept' => 'application/json',
            //     ],
            //     'assertions' => [
            //         [
            //             'data_path' => 'status',
            //             'assertion_type' => 'exists',
            //             'sort_order' => 1,
            //             'is_active' => true,
            //         ],
            //     ],
            // ],
        ],
    ],
];
