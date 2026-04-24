<?php

namespace App\Support;

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

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $empty : $encoded;
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

    private static function normalizeHeaderValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => (string) $item, $value));
        }

        return (string) $value;
    }

    private static function isSensitiveHeader(string $name): bool
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
}
