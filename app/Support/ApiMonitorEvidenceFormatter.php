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
            $httpCode === 0 => 'danger',
            $httpCode >= 500 => 'danger',
            $httpCode >= 400 => 'warning',
            $httpCode >= 200 => 'success',
            default => 'gray',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $assertions
     * @return array<int, array{path: string, type: string, message: string, actual: string, expected: string}>
     */
    public static function normalizeAssertions(?array $assertions): array
    {
        if (blank($assertions)) {
            return [];
        }

        return collect($assertions)
            ->map(function (array $assertion): array {
                // Distinguish *legacy records written before the actual/expected
                // columns existed* (key absent → em-dash) from *new records
                // where the path resolved to genuine JSON null* (key present
                // with null value → "null"). `array_key_exists` is the only
                // check that draws that line correctly.
                $hasActual = array_key_exists('actual', $assertion);
                $hasExpected = array_key_exists('expected', $assertion);

                return [
                    'path' => (string) ($assertion['path'] ?? 'Unknown path'),
                    'type' => (string) ($assertion['type'] ?? 'assertion'),
                    'message' => (string) ($assertion['message'] ?? 'Assertion failed'),
                    'actual' => $hasActual ? self::stringifyAssertionValue($assertion['actual']) : '—',
                    'expected' => $hasExpected ? self::stringifyAssertionValue($assertion['expected']) : '—',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Convert any assertion value (scalar, null, array, object) into a
     * human-readable single-string representation suitable for display in
     * a TextEntry next to its expected counterpart.
     */
    public static function stringifyAssertionValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '[unserializable value]' : $encoded;
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
