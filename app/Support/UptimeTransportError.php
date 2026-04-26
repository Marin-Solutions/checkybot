<?php

namespace App\Support;

class UptimeTransportError
{
    public const DNS = 'dns';

    public const TIMEOUT = 'timeout';

    public const TLS = 'tls';

    public const CONNECTION = 'connection';

    public const UNKNOWN = 'unknown';

    /**
     * @return array{type: string, message: string, code: int|null}
     */
    public static function fromThrowable(\Throwable $exception): array
    {
        $message = trim($exception->getMessage());
        $code = static::extractCode($exception);

        return [
            'type' => static::classify($message, $code),
            'message' => mb_strimwidth($message, 0, 1000, '...'),
            'code' => $code,
        ];
    }

    public static function label(?string $type): string
    {
        return match ($type) {
            self::DNS => 'DNS failure',
            self::TIMEOUT => 'Timeout',
            self::TLS => 'TLS/SSL failure',
            self::CONNECTION => 'Connection failure',
            self::UNKNOWN => 'Transport error',
            default => '-',
        };
    }

    public static function color(?string $type): string
    {
        return match ($type) {
            self::DNS, self::TLS, self::CONNECTION, self::UNKNOWN => 'danger',
            self::TIMEOUT => 'warning',
            default => 'gray',
        };
    }

    public static function summary(?string $type): string
    {
        return match ($type) {
            self::DNS => 'Website heartbeat failed before an HTTP response: DNS lookup failed.',
            self::TIMEOUT => 'Website heartbeat failed before an HTTP response: the request timed out.',
            self::TLS => 'Website heartbeat failed before an HTTP response: TLS/SSL negotiation failed.',
            self::CONNECTION => 'Website heartbeat failed before an HTTP response: the connection could not be established.',
            default => 'Website heartbeat failed before an HTTP response because of a transport error.',
        };
    }

    private static function extractCode(\Throwable $exception): ?int
    {
        foreach ([$exception, $exception->getPrevious()] as $throwable) {
            if (! $throwable) {
                continue;
            }

            if (method_exists($throwable, 'getHandlerContext')) {
                $context = $throwable->getHandlerContext();
                $errno = $context['errno'] ?? $context['curl_errno'] ?? null;

                if (is_numeric($errno)) {
                    return (int) $errno;
                }
            }

            if (preg_match('/cURL error\s+(\d+)/i', $throwable->getMessage(), $matches) === 1) {
                return (int) $matches[1];
            }

            if ($throwable->getCode() > 0) {
                return (int) $throwable->getCode();
            }
        }

        return null;
    }

    private static function classify(string $message, ?int $code): string
    {
        $normalized = mb_strtolower($message);

        return match (true) {
            in_array($code, [6], true)
                || str_contains($normalized, 'could not resolve')
                || str_contains($normalized, 'name or service not known')
                || str_contains($normalized, 'nodename nor servname') => self::DNS,

            in_array($code, [28], true)
                || str_contains($normalized, 'timed out')
                || str_contains($normalized, 'timeout')
                || str_contains($normalized, 'operation timed out') => self::TIMEOUT,

            in_array($code, [35, 51, 58, 59, 60, 77, 80, 83, 90], true)
                || str_contains($normalized, 'ssl')
                || str_contains($normalized, 'tls')
                || str_contains($normalized, 'certificate')
                || str_contains($normalized, 'handshake') => self::TLS,

            in_array($code, [7, 52, 56], true)
                || str_contains($normalized, 'connection refused')
                || str_contains($normalized, 'connection reset')
                || str_contains($normalized, 'network is unreachable')
                || str_contains($normalized, 'no route to host')
                || str_contains($normalized, 'empty reply from server') => self::CONNECTION,

            default => self::UNKNOWN,
        };
    }
}
