<?php

namespace App\Models;

use App\Enums\WebhookHttpMethod;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationChannels extends Model
{
    use HasFactory;

    private const REDACTED_LOG_VALUE = '[redacted]';

    protected $fillable = [
        'title',
        'method',
        'url',
        'description',
        'request_body',
        'created_by',
    ];

    protected $casts = [
        'request_body' => 'array',
    ];

    public static function testWebhook(array $data): array
    {
        $messageTest = "Hello, I'm from ".url('/');
        $method = strtoupper($data['method']);
        $url = $data['url'];
        $responseData = [];
        $requestBody = @$data['request_body'] ?? [];

        try {
            if (str_contains($url, '{message}')) {
                $url = str_replace('{message}', $messageTest, $url);
            }
            if (str_contains($url, '{description}')) {
                $url = str_replace('{description}', $data['description'] ?? '', $url);
            }

            if ($method === WebhookHttpMethod::POST->value && count($data['request_body'])) {
                foreach ($requestBody as $key => $value) {
                    if ($value === '{message}') {
                        $requestBody[$key] = $messageTest;
                    }
                    if ($value === '{description}') {
                        $requestBody[$key] = $data['description'] ?? '';
                    }
                }
            }

            $webhookCallback = match ($method) {
                WebhookHttpMethod::GET->value => Http::{$method}($url),
                default => Http::{$method}($url, $requestBody)
            };

            $responseData['code'] = $webhookCallback->status();
            $responseData['body'] = $webhookCallback->json();

            return $responseData;
        } catch (RequestException $exception) {

            $handlerContext = $exception->getHandlerContext();
            $responseData['code'] = $handlerContext['errno'];
            $responseData['body'] = $handlerContext['error'];

            return $responseData;
        }
    }

    public function sendWebhookNotification(array $data): array
    {
        $messageText = @$data['message'] ?? "Hello, I'm from ".url('/');
        $descriptionText = @$data['description'] ?? 'Description Text';
        $method = $this->method;
        $url = $this->url;
        $responseData = ['url' => $url];
        $requestBody = $this->request_body;

        try {
            Log::info('Preparing webhook notification', [
                'channel_id' => $this->id,
                'original_url' => self::redactWebhookUrlForLogs($url),
                'method' => $method,
                'original_body' => self::redactPayloadForLogs($requestBody),
            ]);

            if (str_contains($url, '{message}')) {
                $url = str_replace('{message}', $messageText, $url);
            }
            if (str_contains($url, '{description}')) {
                $url = str_replace('{description}', $descriptionText, $url);
            }

            if ($method === WebhookHttpMethod::POST->value && count($requestBody)) {
                foreach ($requestBody as $key => $value) {
                    if ($value === '{message}') {
                        $requestBody[$key] = $messageText;
                    }
                    if ($value === '{description}') {
                        $requestBody[$key] = $descriptionText;
                    }
                }
            }

            Log::info('Sending webhook request', [
                'final_url' => self::redactWebhookUrlForLogs($url),
                'final_body' => self::redactPayloadForLogs($requestBody),
            ]);

            $webhookCallback = match ($method) {
                WebhookHttpMethod::GET->value => Http::{$method}($url),
                default => Http::{$method}($url, $requestBody)
            };

            $responseData['code'] = $webhookCallback->status();
            $responseData['body'] = $webhookCallback->json();

            Log::info('Webhook response received', [
                'status_code' => $responseData['code'],
                'response_body' => $responseData['body'],
            ]);

            return $responseData;
        } catch (RequestException $exception) {
            Log::error('Webhook request failed', [
                'error_message' => self::redactWebhookUrlTextForLogs($exception->getMessage(), $url),
                'url' => self::redactWebhookUrlForLogs($url),
                'method' => $method,
                'request_body' => self::redactPayloadForLogs($requestBody),
            ]);

            $handlerContext = $exception->getHandlerContext();
            $responseData['code'] = $handlerContext['errno'];
            $responseData['body'] = $handlerContext['error'];

            return $responseData;
        }
    }

    private static function redactWebhookUrlForLogs(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return self::REDACTED_LOG_VALUE;
        }

        $redactedUrl = '';

        if (isset($parts['scheme'])) {
            $redactedUrl .= $parts['scheme'].'://';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $redactedUrl .= self::REDACTED_LOG_VALUE.'@';
        }

        $redactedUrl .= $parts['host'];

        if (isset($parts['port'])) {
            $redactedUrl .= ':'.$parts['port'];
        }

        $redactedUrl .= self::redactWebhookPathForLogs($parts['path'] ?? '', $parts['host']);

        if (isset($parts['query'])) {
            $redactedUrl .= '?'.self::redactQueryStringForLogs($parts['query']);
        }

        if (isset($parts['fragment'])) {
            $redactedUrl .= '#'.self::REDACTED_LOG_VALUE;
        }

        return $redactedUrl;
    }

    private static function redactWebhookPathForLogs(string $path, string $host): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('/', ltrim($path, '/'));
        $normalizedHost = strtolower($host);

        if ($normalizedHost === 'hooks.slack.com' && ($segments[0] ?? null) === 'services') {
            $redactedSegments = array_fill(0, max(count($segments) - 1, 0), self::REDACTED_LOG_VALUE);

            if ($redactedSegments === []) {
                return '/services';
            }

            return '/services/'.implode('/', $redactedSegments);
        }

        $webhookIndex = array_search('webhooks', $segments, true);
        if ($webhookIndex === false) {
            $webhookIndex = array_search('webhook', $segments, true);
        }

        if ($webhookIndex !== false) {
            foreach ($segments as $index => $segment) {
                if ($index > $webhookIndex && $segment !== '') {
                    $segments[$index] = self::REDACTED_LOG_VALUE;
                }
            }
        }

        // Keep unknown non-webhook path shapes for diagnostics; userinfo, query
        // values, fragments, and known webhook credential paths are still redacted.
        return '/'.implode('/', $segments);
    }

    private static function redactWebhookUrlTextForLogs(string $text, string $url): string
    {
        if ($url === '') {
            return $text;
        }

        // Exception messages commonly include the exact URL or a space-encoded
        // variant; structured URL fields still receive full component redaction.
        return str_replace(
            [$url, str_replace(' ', '%20', $url)],
            self::redactWebhookUrlForLogs($url),
            $text,
        );
    }

    private static function redactQueryStringForLogs(string $query): string
    {
        $segments = array_filter(explode('&', $query), fn (string $segment): bool => $segment !== '');

        if ($segments === []) {
            return self::REDACTED_LOG_VALUE;
        }

        return implode('&', array_map(function (string $segment): string {
            if (! str_contains($segment, '=')) {
                return self::REDACTED_LOG_VALUE;
            }

            [$key] = explode('=', $segment, 2);

            if ($key === '') {
                return self::REDACTED_LOG_VALUE;
            }

            return urldecode($key).'='.self::REDACTED_LOG_VALUE;
        }, $segments));
    }

    private static function redactPayloadForLogs(mixed $payload): mixed
    {
        if (is_array($payload)) {
            return array_map(fn (mixed $value): mixed => self::redactPayloadForLogs($value), $payload);
        }

        if ($payload === null) {
            return null;
        }

        return self::REDACTED_LOG_VALUE;
    }
}
