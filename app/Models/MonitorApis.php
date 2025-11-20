<?php

namespace App\Models;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorApis extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'url',
        'data_path',
        'headers',
        'save_failed_response',
        'created_by',
    ];

    protected $casts = [
        'headers' => 'array',
        'save_failed_response' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public static function testApi(array $data): array
    {
        $url = $data['url'];
        $startTime = microtime(true);

        $responseData = self::initializeResponseData();
        $httpConfig = self::getHttpConfiguration();

        try {
            $headers = self::normalizeHeaders($data['headers'] ?? []);
            $httpClient = self::configureHttpClient($httpConfig, $headers);
            $request = $httpClient->get($url);

            $responseData = self::processSuccessfulResponse($request, $responseData, $startTime, $data);

            if ($responseData['code'] !== 200) {
                return $responseData;
            }

            $responseData = self::parseJsonResponse($responseData);
            if ($responseData['body'] === null && $responseData['code'] === 200) {
                return $responseData;
            }

            $responseData = self::runAssertions($data, $responseData);

            return $responseData;
        } catch (ConnectionException $exception) {
            Log::error('Connection timeout while testing API', [
                'monitor_id' => $data['id'] ?? null,
                'monitor_title' => $data['title'] ?? null,
                'url' => $url,
                'error' => $exception->getMessage(),
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
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            if ($exception->hasResponse()) {
                $responseData['code'] = $exception->getResponse()->getStatusCode();
                $responseData['body'] = $exception->getResponse()->getBody()->getContents();
            } else {
                $handlerContext = $exception->getHandlerContext();
                $responseData['code'] = $handlerContext['errno'] ?? 0;
                $responseData['body'] = $handlerContext['error'] ?? $exception->getMessage();
            }
            $responseData['error'] = $exception->getMessage();

            return $responseData;
        } catch (\Exception $exception) {
            Log::error('Unexpected error while testing API', [
                'monitor_id' => $data['id'] ?? null,
                'monitor_title' => $data['title'] ?? null,
                'url' => $url,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
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

    private static function getHttpConfiguration(): array
    {
        return [
            'timeout' => config('monitor.api_timeout', 10),
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

    private static function configureHttpClient(array $config, array $headers = []): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout($config['timeout'])
            ->retry($config['retries'], $config['retryDelay']);

        if (! empty($headers)) {
            $client = $client->withHeaders($headers);
        }

        return $client;
    }

    private static function processSuccessfulResponse($request, array $responseData, float $startTime, array $data): array
    {
        $responseData['code'] = $request->status();
        $responseData['body'] = $request->body();

        Log::debug('API Monitor response received', [
            'monitor_id' => $data['id'] ?? null,
            'url' => $data['url'],
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
                $responseData['body'] = null;
            } else {
                $responseData['body'] = $parsedBody;
            }
        }

        return $responseData;
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

    private static function runDataPathAssertion(array $data, array $responseData): array
    {
        $value = Arr::get($responseData['body'], $data['data_path']);

        $responseData['assertions'][] = [
            'path' => $data['data_path'],
            'passed' => isset($value),
            'message' => isset($value)
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

            $value = Arr::get($responseData['body'], $assertion->data_path);
            $validationResult = $assertion->validateResponse($value);

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
