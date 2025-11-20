# Checkybot Laravel Package - Implementation Guide

**For: Package Developer**
**Date:** 2025-01-20

## Overview

This document outlines exactly what needs to be built for the Checkybot Laravel package. The package allows developers to define monitoring checks in their Laravel applications and sync them to a Checkybot instance.

## Package Structure

```
checkybot/laravel-monitoring-package/
├── config/
│   └── checkybot.php (publishable config file)
├── src/
│   ├── CheckybotServiceProvider.php
│   ├── Console/
│   │   └── SyncCommand.php
│   ├── Http/
│   │   └── CheckybotClient.php
│   ├── Exceptions/
│   │   └── CheckybotSyncException.php
│   └── ConfigValidator.php
├── tests/
│   ├── Unit/
│   │   ├── ConfigValidatorTest.php
│   │   └── CheckybotClientTest.php
│   └── Feature/
│       └── SyncCommandTest.php
├── composer.json
├── README.md
└── LICENSE
```

## Composer Package Setup

### composer.json

```json
{
    "name": "checkybot/laravel-monitoring",
    "description": "Laravel package for defining and syncing monitoring checks to Checkybot",
    "keywords": ["laravel", "monitoring", "uptime", "api-monitoring", "ssl"],
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.1",
        "illuminate/support": "^10.0|^11.0",
        "guzzlehttp/guzzle": "^7.5"
    },
    "require-dev": {
        "orchestra/testbench": "^8.0|^9.0",
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "Checkybot\\LaravelMonitoring\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Checkybot\\LaravelMonitoring\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Checkybot\\LaravelMonitoring\\CheckybotServiceProvider"
            ]
        }
    }
}
```

## Configuration File

### config/checkybot.php

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Checkybot API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Checkybot instance URL and authentication credentials.
    | You must create a project in Checkybot first and obtain the Project ID.
    |
    */

    'api_key' => env('CHECKYBOT_API_KEY'),
    'project_id' => env('CHECKYBOT_PROJECT_ID'),
    'base_url' => env('CHECKYBOT_URL', 'https://checkybot.com'),

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
            [
                'name' => 'homepage-uptime',
                'url' => env('APP_URL'),
                'interval' => '5m',
                'max_redirects' => 10,
            ],
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
            [
                'name' => 'homepage-ssl',
                'url' => env('APP_URL'),
                'interval' => '1d',
            ],
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
            [
                'name' => 'health-check',
                'url' => env('APP_URL').'/api/health',
                'interval' => '5m',
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.env('HEALTH_CHECK_TOKEN'),
                ],
                'assertions' => [
                    [
                        'data_path' => 'status',
                        'assertion_type' => 'exists',
                        'sort_order' => 1,
                        'is_active' => true,
                    ],
                    [
                        'data_path' => 'status',
                        'assertion_type' => 'comparison',
                        'comparison_operator' => '==',
                        'expected_value' => 'healthy',
                        'sort_order' => 2,
                        'is_active' => true,
                    ],
                    [
                        'data_path' => 'services.database',
                        'assertion_type' => 'comparison',
                        'comparison_operator' => '==',
                        'expected_value' => 'connected',
                        'sort_order' => 3,
                        'is_active' => true,
                    ],
                ],
            ],
        ],
    ],
];
```

## Service Provider

### src/CheckybotServiceProvider.php

```php
<?php

namespace Checkybot\LaravelMonitoring;

use Checkybot\LaravelMonitoring\Console\SyncCommand;
use Illuminate\Support\ServiceProvider;

class CheckybotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/checkybot.php',
            'checkybot'
        );

        $this->app->singleton(Http\CheckybotClient::class, function ($app) {
            return new Http\CheckybotClient(
                config('checkybot.base_url'),
                config('checkybot.api_key'),
                config('checkybot.project_id'),
                config('checkybot.timeout'),
                config('checkybot.retry_times'),
                config('checkybot.retry_delay')
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/checkybot.php' => config_path('checkybot.php'),
            ], 'checkybot-config');

            $this->commands([
                SyncCommand::class,
            ]);
        }
    }
}
```

## HTTP Client

### src/Http/CheckybotClient.php

```php
<?php

namespace Checkybot\LaravelMonitoring\Http;

use Checkybot\LaravelMonitoring\Exceptions\CheckybotSyncException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class CheckybotClient
{
    protected Client $client;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $projectId,
        protected int $timeout = 30,
        protected int $retryTimes = 3,
        protected int $retryDelay = 1000
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => $timeout,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
            ],
        ]);
    }

    public function syncChecks(array $payload): array
    {
        $url = "/api/v1/projects/{$this->projectId}/checks/sync";

        try {
            $response = $this->client->post($url, [
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            Log::info('Checkybot sync successful', [
                'project_id' => $this->projectId,
                'summary' => $body['summary'] ?? null,
            ]);

            return $body;
        } catch (GuzzleException $e) {
            $errorMessage = $this->parseErrorMessage($e);

            Log::error('Checkybot sync failed', [
                'project_id' => $this->projectId,
                'error' => $errorMessage,
                'status_code' => $e->getCode(),
            ]);

            throw new CheckybotSyncException($errorMessage, $e->getCode(), $e);
        }
    }

    protected function parseErrorMessage(GuzzleException $e): string
    {
        if ($e->hasResponse()) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);

            if (isset($body['errors'])) {
                return 'Validation failed: '.json_encode($body['errors']);
            }

            return $body['message'] ?? 'Unknown error occurred';
        }

        return $e->getMessage();
    }
}
```

## Config Validator

### src/ConfigValidator.php

```php
<?php

namespace Checkybot\LaravelMonitoring;

class ConfigValidator
{
    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['api_key'])) {
            $errors[] = 'CHECKYBOT_API_KEY is not configured';
        }

        if (empty($config['project_id'])) {
            $errors[] = 'CHECKYBOT_PROJECT_ID is not configured';
        }

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $checks = $config['checks'] ?? [];
        $this->validateCheckNames($checks, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    protected function validateCheckNames(array $checks, array &$errors): void
    {
        foreach (['uptime', 'ssl', 'api'] as $type) {
            $names = array_column($checks[$type] ?? [], 'name');
            $duplicates = array_diff_assoc($names, array_unique($names));

            if (! empty($duplicates)) {
                $errors[] = "Duplicate {$type} check names found: ".implode(', ', $duplicates);
            }
        }
    }

    public function transformPayload(array $config): array
    {
        return [
            'uptime_checks' => $config['checks']['uptime'] ?? [],
            'ssl_checks' => $config['checks']['ssl'] ?? [],
            'api_checks' => $config['checks']['api'] ?? [],
        ];
    }
}
```

## Sync Command

### src/Console/SyncCommand.php

```php
<?php

namespace Checkybot\LaravelMonitoring\Console;

use Checkybot\LaravelMonitoring\ConfigValidator;
use Checkybot\LaravelMonitoring\Exceptions\CheckybotSyncException;
use Checkybot\LaravelMonitoring\Http\CheckybotClient;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'checkybot:sync
                          {--dry-run : Show what would be synced without actually syncing}';

    protected $description = 'Sync monitoring checks to Checkybot';

    public function handle(CheckybotClient $client, ConfigValidator $validator): int
    {
        $this->info('Checkybot Sync Starting...');

        $config = config('checkybot');

        $validation = $validator->validate($config);

        if (! $validation['valid']) {
            $this->error('Configuration validation failed:');
            foreach ($validation['errors'] as $error) {
                $this->error('  - '.$error);
            }

            return self::FAILURE;
        }

        $payload = $validator->transformPayload($config);

        $totalChecks = count($payload['uptime_checks'])
            + count($payload['ssl_checks'])
            + count($payload['api_checks']);

        $this->comment("Found {$totalChecks} checks to sync");

        if ($this->option('dry-run')) {
            $this->displayDryRun($payload);

            return self::SUCCESS;
        }

        try {
            $response = $client->syncChecks($payload);

            $this->displaySyncResults($response['summary'] ?? []);

            $this->info('✓ Sync completed successfully');

            return self::SUCCESS;
        } catch (CheckybotSyncException $e) {
            $this->error('✗ Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function displayDryRun(array $payload): void
    {
        $this->line('');
        $this->comment('DRY RUN - No changes will be made');
        $this->line('');

        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $type) {
            if (! empty($payload[$type])) {
                $this->info(ucwords(str_replace('_', ' ', $type)).':');
                foreach ($payload[$type] as $check) {
                    $this->line("  - {$check['name']} ({$check['url']}) every {$check['interval']}");
                }
                $this->line('');
            }
        }
    }

    protected function displaySyncResults(array $summary): void
    {
        $this->line('');
        $this->info('Sync Summary:');

        foreach ($summary as $type => $counts) {
            $label = ucwords(str_replace('_', ' ', $type));
            $this->line("  {$label}:");
            $this->line("    Created: {$counts['created']}");
            $this->line("    Updated: {$counts['updated']}");
            $this->line("    Deleted: {$counts['deleted']}");
        }

        $this->line('');
    }
}
```

## Exception

### src/Exceptions/CheckybotSyncException.php

```php
<?php

namespace Checkybot\LaravelMonitoring\Exceptions;

class CheckybotSyncException extends \Exception
{
}
```

## README.md

Provide comprehensive documentation including:

### Installation

```bash
composer require checkybot/laravel-monitoring
php artisan vendor:publish --tag=checkybot-config
```

### Configuration

1. Create a project in Checkybot dashboard
2. Generate an API key
3. Add to .env:
   ```
   CHECKYBOT_API_KEY=your-api-key
   CHECKYBOT_PROJECT_ID=1
   CHECKYBOT_URL=https://checkybot.com
   ```

### Defining Checks

Edit `config/checkybot.php` and define your checks as shown in the configuration file above.

### Syncing

```bash
# Sync checks to Checkybot
php artisan checkybot:sync

# Preview what would be synced (dry run)
php artisan checkybot:sync --dry-run
```

### CI/CD Integration

Add to your deployment pipeline:

```yaml
# GitHub Actions example
- name: Sync Checkybot Monitors
  run: php artisan checkybot:sync
  env:
    CHECKYBOT_API_KEY: ${{ secrets.CHECKYBOT_API_KEY }}
    CHECKYBOT_PROJECT_ID: ${{ secrets.CHECKYBOT_PROJECT_ID }}
```

### Interval Format

- `5m` = every 5 minutes
- `1h` = every hour
- `2h` = every 2 hours
- `1d` = once per day

### Assertion Types

#### Exists
Checks if a JSON path exists in the response:
```php
[
    'data_path' => 'status',
    'assertion_type' => 'exists',
]
```

#### Comparison
Compares a value using an operator:
```php
[
    'data_path' => 'count',
    'assertion_type' => 'comparison',
    'comparison_operator' => '>=',
    'expected_value' => '1',
]
```

Operators: `==`, `!=`, `>`, `>=`, `<`, `<=`

#### Type
Checks if value matches expected type:
```php
[
    'data_path' => 'id',
    'assertion_type' => 'type',
    'expected_type' => 'integer',
]
```

#### Regex
Matches value against regex pattern:
```php
[
    'data_path' => 'email',
    'assertion_type' => 'regex',
    'regex_pattern' => '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/',
]
```

## Testing

### Unit Tests

Test the config validator, HTTP client (mocked), and payload transformation.

### Feature Tests

Test the sync command with mocked HTTP responses.

### Example Test

```php
<?php

namespace Checkybot\LaravelMonitoring\Tests\Feature;

use Checkybot\LaravelMonitoring\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SyncCommandTest extends TestCase
{
    public function test_sync_command_sends_checks_to_checkybot()
    {
        config([
            'checkybot.api_key' => 'test-key',
            'checkybot.project_id' => '1',
            'checkybot.checks' => [
                'uptime' => [
                    ['name' => 'test', 'url' => 'https://example.com', 'interval' => '5m'],
                ],
                'ssl' => [],
                'api' => [],
            ],
        ]);

        Http::fake([
            '*/api/v1/projects/1/checks/sync' => Http::response([
                'message' => 'Checks synced successfully',
                'summary' => [
                    'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ], 200),
        ]);

        $this->artisan('checkybot:sync')
            ->expectsOutput('✓ Sync completed successfully')
            ->assertExitCode(0);
    }
}
```

## Checkybot API Endpoint

The package will call:

```
POST https://checkybot.com/api/v1/projects/{project_id}/checks/sync
Authorization: Bearer {api_key}
Content-Type: application/json

{
  "uptime_checks": [...],
  "ssl_checks": [...],
  "api_checks": [...]
}
```

Expected responses:

**Success (200):**
```json
{
  "message": "Checks synced successfully",
  "summary": {
    "uptime_checks": {"created": 1, "updated": 0, "deleted": 0},
    "ssl_checks": {"created": 1, "updated": 0, "deleted": 0},
    "api_checks": {"created": 1, "updated": 2, "deleted": 3}
  }
}
```

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "uptime_checks.0.url": ["The url field must be a valid URL."]
  }
}
```

**Authorization Error (403):**
```json
{
  "message": "You do not have permission to manage this project."
}
```

## Implementation Checklist

- [ ] Create package repository structure
- [ ] Set up composer.json with dependencies
- [ ] Implement CheckybotServiceProvider
- [ ] Create publishable config file
- [ ] Implement CheckybotClient with Guzzle
- [ ] Implement ConfigValidator
- [ ] Implement SyncCommand
- [ ] Create CheckybotSyncException
- [ ] Write comprehensive README.md
- [ ] Add unit tests
- [ ] Add feature tests
- [ ] Set up CI/CD for package
- [ ] Publish to Packagist

## Additional Considerations

### Error Handling

Handle these scenarios gracefully:
- Missing/invalid API credentials
- Network timeouts
- Invalid config format
- API validation errors
- Unauthorized access

### Logging

Log important events:
- Sync started/completed
- Number of checks synced
- API errors
- Validation failures

### User Experience

- Clear error messages
- Helpful configuration examples
- Dry-run mode for testing
- Progress indicators for sync
- Summary of changes after sync

## Support & Documentation

Provide:
- Installation guide
- Configuration guide
- All check types with examples
- Assertion syntax reference
- Interval format specification
- Troubleshooting guide
- CI/CD integration examples
- FAQ section

This implementation guide provides everything needed to build the package!
