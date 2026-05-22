<?php

namespace App\Support;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
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

    public static function replayTemplate(MonitorApiResult $result): ?string
    {
        $monitor = $result->monitorApi;

        if (! $monitor instanceof MonitorApis || blank($monitor->url)) {
            return null;
        }

        $parts = [
            'curl --request '.self::shellArg(strtoupper((string) ($monitor->http_method ?: 'GET'))),
            '  --url '.self::shellArg(self::sanitizeReplayUrl((string) $monitor->url)),
        ];

        foreach (self::headersForReplay($result->request_headers) as $name => $value) {
            $parts[] = '  --header '.self::shellArg("{$name}: {$value}");
        }

        $body = self::bodyForReplay($monitor);

        if ($body !== null) {
            foreach ($body['headers'] as $name => $value) {
                if (! self::hasHeader($result->request_headers, $name)) {
                    $parts[] = '  --header '.self::shellArg("{$name}: {$value}");
                }
            }

            $parts[] = '  --data-raw '.self::shellArg($body['value']);
        }

        return implode(" \\\n", $parts);
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

    public static function compactLatestEvidence(?MonitorApiResult $result): ?string
    {
        if ($result === null) {
            return null;
        }

        $failedAssertions = self::normalizeAssertions($result->failed_assertions);
        $assertionCount = count($failedAssertions);
        $firstFailingPath = $failedAssertions[0]['path'] ?? '-';
        $transportType = match (true) {
            filled($result->transport_error_type) => $result->transport_error_type,
            $result->http_code === 0 => 'no response',
            default => 'ok',
        };

        return sprintf(
            'HTTP %s | %s | %d failed | %s',
            $result->http_code ?? '-',
            $transportType,
            $assertionCount,
            $firstFailingPath,
        );
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
        $compact = str_replace(['-', '_', ' '], '', $normalized);

        return $normalized === 'authorization'
            || str_contains($compact, 'token')
            || str_contains($compact, 'secret')
            || str_contains($compact, 'apikey')
            || str_contains($compact, 'auth')
            || str_contains($compact, 'signature')
            || str_contains($compact, 'cookie')
            || str_contains($compact, 'password');
    }

    /**
     * @param  array<string, mixed>|null  $headers
     * @return array<string, string>
     */
    private static function headersForReplay(?array $headers): array
    {
        return collect(self::maskHeaders($headers))
            ->mapWithKeys(fn (string $value, string $name): array => [
                $name => self::redactedHeaderPlaceholder($name, $value),
            ])
            ->all();
    }

    private static function redactedHeaderPlaceholder(string $name, string $value): string
    {
        if ($value !== '[redacted]') {
            return $value;
        }

        $placeholder = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?: 'HEADER');

        return "<REPLACE_{$placeholder}>";
    }

    /**
     * @return array{value: string, headers: array<string, string>}|null
     */
    private static function bodyForReplay(MonitorApis $monitor): ?array
    {
        $bodyType = strtolower((string) $monitor->request_body_type);
        $body = $monitor->request_body;

        if ($body === null || $body === '' || ! in_array($bodyType, ['json', 'form'], true)) {
            return null;
        }

        $structured = self::structuredBody($body);

        if (! is_array($structured)) {
            return null;
        }

        $redacted = ApiMonitorEvidenceRedactor::redactSavedResponseBody($structured);

        if ($bodyType === 'json') {
            $encoded = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return $encoded === false ? null : [
                'value' => $encoded,
                'headers' => ['Content-Type' => 'application/json'],
            ];
        }

        return [
            'value' => http_build_query($redacted, '', '&', PHP_QUERY_RFC3986),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ];
    }

    private static function structuredBody(mixed $body): mixed
    {
        if (is_array($body)) {
            return $body;
        }

        if (! is_string($body)) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        parse_str($body, $parsed);

        return $parsed === [] ? null : $parsed;
    }

    /**
     * @param  array<string, mixed>|null  $headers
     */
    private static function hasHeader(?array $headers, string $name): bool
    {
        if (blank($headers)) {
            return false;
        }

        $wanted = strtolower($name);

        foreach (array_keys($headers) as $header) {
            if (strtolower((string) $header) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function sanitizeReplayUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $authority = $parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        $path = $parts['path'] ?? '';
        $query = self::sanitizeReplayQuery($parts['query'] ?? null);
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$authority}{$path}{$query}{$fragment}";
    }

    private static function sanitizeReplayQuery(?string $query): string
    {
        if (blank($query)) {
            return '';
        }

        $pairs = [];

        foreach (explode('&', (string) $query) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            $decodedKey = urldecode($key);

            if ($value !== null && self::isSensitiveHeader($decodedKey)) {
                $value = rawurlencode('<REPLACE_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $decodedKey) ?: 'VALUE').'>');
            }

            $pairs[] = $value === null ? $key : "{$key}={$value}";
        }

        return '?'.implode('&', $pairs);
    }

    private static function shellArg(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private static function normalizeHeaderValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => (string) $item, $value));
        }

        return (string) $value;
    }
}
