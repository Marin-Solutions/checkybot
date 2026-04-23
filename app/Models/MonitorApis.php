<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorApis extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'url',
        'http_method',
        'request_path',
        'data_path',
        'headers',
        'expected_status',
        'timeout_seconds',
        'package_schedule',
        'is_enabled',
        'last_synced_at',
        'save_failed_response',
        'created_by',
        'project_id',
        'source',
        'package_name',
        'package_interval',
        'current_status',
        'last_heartbeat_at',
        'stale_at',
        'status_summary',
    ];

    protected $casts = [
        'save_failed_response' => 'boolean',
        'expected_status' => 'integer',
        'timeout_seconds' => 'integer',
        'is_enabled' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    protected function headers(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): array => $this->decryptHeaders($value),
            set: fn (mixed $value): ?string => $this->encryptHeaders($value),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assertions(): HasMany
    {
        return $this->hasMany(MonitorApiAssertion::class, 'monitor_api_id')
            ->orderBy('sort_order');
    }

    public function results(): HasMany
    {
        return $this->hasMany(MonitorApiResult::class, 'monitor_api_id');
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(MonitorApiResult::class, 'monitor_api_id')->latestOfMany();
    }

    public static function testApi(array $data): array
    {
        $url = $data['url'];
        $startTime = microtime(true);
        $method = strtoupper((string) ($data['method'] ?? $data['http_method'] ?? 'GET'));
        $expectedStatus = isset($data['expected_status']) ? (int) $data['expected_status'] : null;

        $responseData = self::initializeResponseData();
        $httpConfig = self::getHttpConfiguration($data);
        $sanitizedUrl = self::sanitizeUrlForLogs($url);

        try {
            // Get headers from data or fetch from database
            $headers = self::normalizeHeaders(
                $data['headers'] ?? (isset($data['id']) ? self::find($data['id'])?->headers : [])
            );
            $httpClient = self::configureHttpClient($httpConfig, $headers);
            $request = $httpClient->send($method, $url);

            $responseData = self::processSuccessfulResponse($request, $responseData, $startTime, $data, $sanitizedUrl, $method);
            $responseData = self::applyExpectedStatusAssertion($responseData, $expectedStatus);

            if (! self::requiresJsonAssertions($data)) {
                return $responseData;
            }

            $responseData = self::parseJsonResponse($responseData);
            if (self::jsonParsingFailed($responseData)) {
                return $responseData;
            }

            $responseData = self::runAssertions($data, $responseData);

            return $responseData;
        } catch (ConnectionException $exception) {
            Log::error('Connection timeout while testing API', [
                'monitor_id' => $data['id'] ?? null,
                'monitor_title' => $data['title'] ?? null,
                'method' => $method,
                'url' => $sanitizedUrl,
                'error' => self::sanitizeLogMessage($exception->getMessage(), $url),
                'timeout' => $httpConfig['timeout'],
                'retries' => $httpConfig['retries'],
            ]);

            $responseData['code'] = 0;
            $responseData['body'] = null;
            $responseData['error'] = 'Connection timeout: '.$exception->getMessage();

            return $responseData;
        } catch (RequestException $exception) {
            Log::error('Request error while testing API', [
                'monitor_id' => $data['id'] ?? null,
                'monitor_title' => $data['title'] ?? null,
                'method' => $method,
                'url' => $sanitizedUrl,
                'error' => self::sanitizeLogMessage($exception->getMessage(), $url),
            ]);

            if ($exception->response) {
                $responseData['code'] = $exception->response->status();
                $responseData['body'] = $exception->response->body();
            } else {
                $responseData['code'] = 0;
                $responseData['body'] = $exception->getMessage();
            }
            $responseData['error'] = $exception->getMessage();

            return $responseData;
        } catch (\Exception $exception) {
            Log::error('Unexpected error while testing API', [
                'monitor_id' => $data['id'] ?? null,
                'monitor_title' => $data['title'] ?? null,
                'method' => $method,
                'url' => $sanitizedUrl,
                'error' => self::sanitizeLogMessage($exception->getMessage(), $url),
            ]);

            $responseData['code'] = 0;
            $responseData['body'] = null;
            $responseData['error'] = 'Unexpected error: '.$exception->getMessage();

            return $responseData;
        }
    }

    private static function initializeResponseData(): array
    {
        return [
            'code' => 0,
            'body' => null,
            'assertions' => [],
            'error' => null,
        ];
    }

    private static function getHttpConfiguration(array $data): array
    {
        $timeout = (int) ($data['timeout_seconds'] ?? config('monitor.api_timeout', 10));

        return [
            'timeout' => $timeout > 0 ? $timeout : config('monitor.api_timeout', 10),
            'retries' => config('monitor.api_retries', 3),
            'retryDelay' => config('monitor.api_retry_delay', 1000),
        ];
    }

    private static function normalizeHeaders(mixed $headers): array
    {
        if (empty($headers)) {
            return [];
        }

        if (is_string($headers)) {
            $decoded = json_decode($headers, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($headers) ? $headers : [];
    }

    private function decryptHeaders(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['encrypted']) && is_string($decoded['encrypted'])) {
            try {
                $decrypted = Crypt::decryptString($decoded['encrypted']);
                $headers = json_decode($decrypted, true);

                return is_array($headers) ? $headers : [];
            } catch (DecryptException) {
                return [];
            }
        }

        return $decoded;
    }

    private function encryptHeaders(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $headers = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($headers)) {
            return null;
        }

        return json_encode([
            'encrypted' => Crypt::encryptString(json_encode($headers)),
        ]);
    }

    private static function configureHttpClient(array $config, array $headers = []): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout($config['timeout'])
            ->retry($config['retries'], $config['retryDelay'], throw: false);

        if (! empty($headers)) {
            $client = $client->withHeaders($headers);
        }

        return $client;
    }

    private static function processSuccessfulResponse($request, array $responseData, float $startTime, array $data, string $sanitizedUrl, string $method): array
    {
        $responseData['code'] = $request->status();
        $responseData['body'] = $request->body();

        Log::debug('API Monitor response received', [
            'monitor_id' => $data['id'] ?? null,
            'method' => $method,
            'url' => $sanitizedUrl,
            'status' => $request->status(),
            'response_time' => round((microtime(true) - $startTime) * 1000, 2).'ms',
        ]);

        return $responseData;
    }

    private static function parseJsonResponse(array $responseData): array
    {
        if (is_string($responseData['body'])) {
            $parsedBody = json_decode($responseData['body'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $responseData['error'] = 'Invalid JSON response: '.json_last_error_msg();
                $responseData['assertions'][] = [
                    'path' => '_response_body',
                    'type' => 'json_valid',
                    'passed' => false,
                    'message' => $responseData['error'],
                ];
                $responseData['body'] = null;
            } else {
                $responseData['body'] = $parsedBody;
            }
        }

        return $responseData;
    }

    private static function jsonParsingFailed(array $responseData): bool
    {
        return collect($responseData['assertions'] ?? [])
            ->contains(fn (array $assertion): bool => ($assertion['type'] ?? null) === 'json_valid'
                && ($assertion['passed'] ?? true) === false);
    }

    private static function runAssertions(array $data, array $responseData): array
    {
        // Prioritize stored assertions over simple data_path check
        if (isset($data['id'])) {
            $api = self::with('assertions')->find($data['id']);

            // If monitor has stored assertions, use those
            if ($api && $api->assertions->isNotEmpty()) {
                return self::runStoredAssertions($data, $responseData);
            }
        }

        // Fall back to simple data_path existence check
        if (isset($data['data_path'])) {
            return self::runDataPathAssertion($data, $responseData);
        }

        return $responseData;
    }

    private static function requiresJsonAssertions(array $data): bool
    {
        if (! empty($data['data_path'])) {
            return true;
        }

        if (isset($data['id'])) {
            return self::with('assertions')
                ->find($data['id'])
                ?->assertions
                ->isNotEmpty() ?? false;
        }

        return false;
    }

    private static function applyExpectedStatusAssertion(array $responseData, ?int $expectedStatus): array
    {
        if ($expectedStatus === null) {
            return $responseData;
        }

        if (($responseData['code'] ?? null) === $expectedStatus) {
            return $responseData;
        }

        $responseData['assertions'][] = [
            'path' => '_http_status',
            'type' => 'status_code',
            'passed' => false,
            'message' => "Expected HTTP status {$expectedStatus}, got {$responseData['code']}.",
        ];

        return $responseData;
    }

    private static function sanitizeUrlForLogs(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        $sanitized = collect($query)
            ->mapWithKeys(fn (mixed $value, string $key): array => [
                $key => self::isSensitiveField($key) ? '[redacted]' : $value,
            ])
            ->all();

        $rebuiltQuery = http_build_query($sanitized);

        return str_replace('?'.$parts['query'], $rebuiltQuery === '' ? '' : '?'.$rebuiltQuery, $url);
    }

    private static function sanitizeLogMessage(string $message, string $rawUrl): string
    {
        $sanitizedUrl = self::sanitizeUrlForLogs($rawUrl);

        if ($sanitizedUrl === $rawUrl) {
            return $message;
        }

        return str_replace($rawUrl, $sanitizedUrl, $message);
    }

    private static function isSensitiveField(string $name): bool
    {
        $normalized = strtolower($name);

        return $normalized === 'authorization'
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'api-key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'auth')
            || str_contains($normalized, 'signature');
    }

    private static function runDataPathAssertion(array $data, array $responseData): array
    {
        $missing = new \stdClass;
        $value = Arr::get($responseData['body'], $data['data_path'], $missing);
        $exists = $value !== $missing;

        $responseData['assertions'][] = [
            'path' => $data['data_path'],
            'passed' => $exists,
            'message' => $exists
                ? 'Value exists at path'
                : 'Value does not exist at path',
        ];

        return $responseData;
    }

    private static function runStoredAssertions(array $data, array $responseData): array
    {
        $api = self::with('assertions')->find($data['id']);

        if (! $api) {
            return $responseData;
        }

        foreach ($api->assertions as $assertion) {
            if (! $assertion->is_active) {
                continue;
            }

            $missing = new \stdClass;
            $value = Arr::get($responseData['body'], $assertion->data_path, $missing);
            $exists = $value !== $missing;
            $validationResult = $assertion->validateResponse($exists ? $value : null, $exists);

            $responseData['assertions'][] = [
                'path' => $assertion->data_path,
                'type' => $assertion->assertion_type,
                'passed' => $validationResult['passed'],
                'message' => $validationResult['message'],
            ];
        }

        return $responseData;
    }
}
