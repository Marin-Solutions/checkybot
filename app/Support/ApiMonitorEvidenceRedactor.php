<?php

namespace App\Support;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Illuminate\Support\Str;

class ApiMonitorEvidenceRedactor
{
    private const MAX_EVIDENCE_STRING_LENGTH = 4096;

    private const MAX_SAVED_RESPONSE_BODY_LENGTH = 32768;

    private const TRUNCATED_PAYLOAD_KEY = '__checky_truncated_payload__';

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function redactHeaders(array $headers): array
    {
        return collect($headers)
            ->mapWithKeys(fn (mixed $value, string $name): array => [
                $name => self::redactValue($value, $name),
            ])
            ->all();
    }

    /**
     * @return mixed Redacted response body evidence safe for API payloads.
     */
    public static function redactResponseBody(mixed $responseBody): mixed
    {
        if (is_string($responseBody)) {
            return '[redacted]';
        }

        return self::redactValue($responseBody);
    }

    /**
     * @param  array<string, mixed>  $responseBody
     * @return array<string, mixed>
     */
    public static function redactSavedResponseBody(array $responseBody): array
    {
        $redacted = self::redactValue($responseBody);

        if (! is_array($redacted)) {
            return [];
        }

        $encoded = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false || strlen($encoded) <= self::MAX_SAVED_RESPONSE_BODY_LENGTH) {
            return $redacted;
        }

        return self::truncateSavedPayload($encoded);
    }

    /**
     * @return string|null Redacted and truncated transport error evidence.
     */
    public static function redactTransportErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return Str::limit(self::sanitizeString($message), self::MAX_EVIDENCE_STRING_LENGTH, '... [truncated]');
    }

    private static function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            return collect($value)
                ->mapWithKeys(fn (mixed $item, int|string $itemKey): array => [
                    $itemKey => self::redactValue(
                        $item,
                        is_string($itemKey) ? $itemKey : null,
                    ),
                ])
                ->all();
        }

        if (is_string($value)) {
            return Str::limit(self::sanitizeString($value), self::MAX_EVIDENCE_STRING_LENGTH, '... [truncated]');
        }

        return $value;
    }

    private static function isSensitiveKey(string $name): bool
    {
        $normalized = strtolower($name);
        $compact = str_replace(['-', '_', ' '], '', $normalized);

        return $name === MonitorApiResult::RAW_BODY_KEY
            || $name === MonitorApiResult::ERROR_METADATA_KEY
            || $name === MonitorApis::LEGACY_RAW_BODY_KEY
            || str_contains($compact, 'authorization')
            // Prefer over-redaction for evidence payload keys because saved
            // response bodies may contain arbitrary customer API structures.
            || str_contains($compact, 'token')
            || str_contains($compact, 'secret')
            || str_contains($compact, 'apikey')
            || str_contains($compact, 'authkey')
            || str_contains($compact, 'signature')
            || str_contains($compact, 'cookie')
            || str_contains($compact, 'password');
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace_callback(
            '~https?://[^\s<>"\')]+~i',
            fn (array $matches): string => self::redactUrl($matches[0]),
            $value,
        ) ?? $value;

        $value = preg_replace(
            '~\b(token|secret|api[_-]?key|auth[_-]?key|password|signature)=([^\s&]+)~i',
            '$1=[redacted]',
            $value,
        ) ?? $value;

        return preg_replace(
            '#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]+#i',
            '$1 [redacted]',
            $value,
        ) ?? $value;
    }

    /**
     * @return array<string, string>
     */
    private static function truncateSavedPayload(string $encodedPayload): array
    {
        $suffix = '... [truncated]';
        $availableBytes = self::MAX_SAVED_RESPONSE_BODY_LENGTH - strlen(json_encode(
            [self::TRUNCATED_PAYLOAD_KEY => $suffix],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));

        do {
            $truncated = mb_strcut($encodedPayload, 0, max(0, $availableBytes), 'UTF-8').$suffix;
            $payload = [self::TRUNCATED_PAYLOAD_KEY => $truncated];
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($encoded === false || strlen($encoded) <= self::MAX_SAVED_RESPONSE_BODY_LENGTH) {
                return $payload;
            }

            $availableBytes -= max(1, strlen($encoded) - self::MAX_SAVED_RESPONSE_BODY_LENGTH);
        } while ($availableBytes > 0);

        return [self::TRUNCATED_PAYLOAD_KEY => $suffix];
    }

    private static function redactUrl(string $url): string
    {
        $trailing = '';

        while ($url !== '' && str_contains('.,', substr($url, -1))) {
            $trailing = substr($url, -1).$trailing;
            $url = substr($url, 0, -1);
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '[redacted-url]'.$trailing;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$parts['host']}{$port}/[redacted-url]{$trailing}";
    }
}
