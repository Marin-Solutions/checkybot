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

            $responseData['code'] = $webhookCallback->ok() ? 200 : 0;
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
                'original_url' => $url,
                'method' => $method,
                'original_body' => $requestBody,
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
                'final_url' => $url,
                'final_body' => $requestBody,
            ]);

            $webhookCallback = match ($method) {
                WebhookHttpMethod::GET->value => Http::{$method}($url),
                default => Http::{$method}($url, $requestBody)
            };

            $responseData['code'] = $webhookCallback->ok() ? 200 : 0;
            $responseData['body'] = $webhookCallback->json();

            Log::info('Webhook response received', [
                'status_code' => $responseData['code'],
                'response_body' => $responseData['body'],
            ]);

            return $responseData;
        } catch (RequestException $exception) {
            Log::error('Webhook request failed', [
                'error_message' => $exception->getMessage(),
                'url' => $url,
                'method' => $method,
                'request_body' => $requestBody,
            ]);

            $handlerContext = $exception->getHandlerContext();
            $responseData['code'] = $handlerContext['errno'];
            $responseData['body'] = $handlerContext['error'];

            return $responseData;
        }
    }
}
