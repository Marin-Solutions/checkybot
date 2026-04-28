<?php

namespace App\Support;

use App\Enums\UptimeTransportErrorType;

class UptimeTransportError
{
    /**
     * @return array{type: UptimeTransportErrorType, message: string, code: int|null}
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

    public static function label(UptimeTransportErrorType|string|null $type): string
    {
        return match (static::normalizeType($type)) {
            UptimeTransportErrorType::Dns => 'DNS failure',
            UptimeTransportErrorType::Timeout => 'Timeout',
            UptimeTransportErrorType::Tls => 'TLS/SSL failure',
            UptimeTransportErrorType::Connection => 'Connection failure',
            UptimeTransportErrorType::Unknown => 'Transport error',
            null => '-',
        };
    }

    public static function color(UptimeTransportErrorType|string|null $type): string
    {
        return match (static::normalizeType($type)) {
            UptimeTransportErrorType::Dns,
            UptimeTransportErrorType::Tls,
            UptimeTransportErrorType::Connection,
            UptimeTransportErrorType::Unknown => 'danger',
            UptimeTransportErrorType::Timeout => 'warning',
            null => 'gray',
        };
    }

    public static function summary(UptimeTransportErrorType|string|null $type, string $subject = 'Website heartbeat'): string
    {
        return match (static::normalizeType($type)) {
            UptimeTransportErrorType::Dns => "{$subject} failed before an HTTP response: DNS lookup failed.",
            UptimeTransportErrorType::Timeout => "{$subject} failed before an HTTP response: the request timed out.",
            UptimeTransportErrorType::Tls => "{$subject} failed before an HTTP response: TLS/SSL negotiation failed.",
            UptimeTransportErrorType::Connection => "{$subject} failed before an HTTP response: the connection could not be established.",
            default => "{$subject} failed before an HTTP response because of a transport error.",
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

    private static function classify(string $message, ?int $code): UptimeTransportErrorType
    {
        $normalized = mb_strtolower($message);

        return match (true) {
            in_array($code, [6], true)
                || str_contains($normalized, 'could not resolve')
                || str_contains($normalized, 'name or service not known')
                || str_contains($normalized, 'nodename nor servname') => UptimeTransportErrorType::Dns,

            in_array($code, [28], true)
                || str_contains($normalized, 'timed out')
                || str_contains($normalized, 'timeout')
                || str_contains($normalized, 'operation timed out') => UptimeTransportErrorType::Timeout,

            in_array($code, [35, 51, 58, 59, 60, 77, 80, 83, 90], true)
                || str_contains($normalized, 'ssl')
                || str_contains($normalized, 'tls')
                || str_contains($normalized, 'certificate')
                || str_contains($normalized, 'handshake') => UptimeTransportErrorType::Tls,

            in_array($code, [7, 52, 56], true)
                || str_contains($normalized, 'connection refused')
                || str_contains($normalized, 'connection reset')
                || str_contains($normalized, 'network is unreachable')
                || str_contains($normalized, 'no route to host')
                || str_contains($normalized, 'empty reply from server') => UptimeTransportErrorType::Connection,

            default => UptimeTransportErrorType::Unknown,
        };
    }

    private static function normalizeType(UptimeTransportErrorType|string|null $type): ?UptimeTransportErrorType
    {
        if ($type instanceof UptimeTransportErrorType || $type === null) {
            return $type;
        }

        return UptimeTransportErrorType::tryFrom($type);
    }
}
