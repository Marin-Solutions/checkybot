<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class ApiMonitorEvidenceFormatter
{
    /**
     * @param  array<string, mixed>|null  $headers
     * @return array<string, string>
     */
    public static function maskHeaders(?array $headers): array
    {
        if (blank($headers)) {
            return [];
        }

        return collect($headers)
            ->mapWithKeys(function (mixed $value, string $key): array {
                if (self::isSensitiveHeader($key)) {
                    return [$key => '[redacted]'];
                }

                return [$key => self::normalizeHeaderValue($value)];
            })
            ->all();
    }

    public static function formatPayload(mixed $payload, string $empty = '{}'): string
    {
        if (blank($payload)) {
            return $empty;
        }

        if (is_string($payload)) {
            return $payload;
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? $empty : $encoded;
    }

    public static function formatAsPreHtml(string $value): HtmlString
    {
        return new HtmlString('<pre style="white-space: pre-wrap; margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;">'.e($value).'</pre>');
    }

    public static function httpCodeColor(?int $httpCode): string
    {
        return match (true) {
            $httpCode === null => 'gray',
            $httpCode >= 500 => 'danger',
            $httpCode >= 400 => 'warning',
            $httpCode >= 200 => 'success',
            default => 'gray',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $assertions
     * @return array<int, array{path: string, type: string, message: string}>
     */
    public static function normalizeAssertions(?array $assertions): array
    {
        if (blank($assertions)) {
            return [];
        }

        return collect($assertions)
            ->map(fn (array $assertion): array => [
                'path' => (string) ($assertion['path'] ?? 'Unknown path'),
                'type' => (string) ($assertion['type'] ?? 'assertion'),
                'message' => (string) ($assertion['message'] ?? 'Assertion failed'),
            ])
            ->values()
            ->all();
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'healthy' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'gray',
        };
    }

    public static function isSensitiveHeader(string $name): bool
    {
        $normalized = strtolower($name);

        return $normalized === 'authorization'
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'api-key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'auth')
            || str_contains($normalized, 'signature')
            || str_contains($normalized, 'cookie');
    }

    private static function normalizeHeaderValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => (string) $item, $value));
        }

        return (string) $value;
    }
}
