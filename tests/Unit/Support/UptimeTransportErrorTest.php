<?php

use App\Enums\UptimeTransportErrorType;
use App\Support\UptimeTransportError;
use Illuminate\Http\Client\ConnectionException;

test('classifies transport errors from curl message codes', function (
    string $message,
    UptimeTransportErrorType $expectedType,
    ?int $expectedCode,
) {
    $error = UptimeTransportError::fromThrowable(new ConnectionException($message));

    expect($error['type'])->toBe($expectedType)
        ->and($error['code'])->toBe($expectedCode);
})->with([
    'dns' => ['cURL error 6: Could not resolve host: missing.example', UptimeTransportErrorType::Dns, 6],
    'timeout' => ['cURL error 28: Operation timed out after 10001 milliseconds', UptimeTransportErrorType::Timeout, 28],
    'tls' => ['cURL error 60: SSL certificate problem: unable to get local issuer certificate', UptimeTransportErrorType::Tls, 60],
    'connection' => ['cURL error 7: Failed to connect to example.com port 443', UptimeTransportErrorType::Connection, 7],
    'unknown' => ['The request failed before an HTTP response was received.', UptimeTransportErrorType::Unknown, null],
]);

test('extracts curl errno from handler context', function () {
    $exception = new class('Handler reported TLS handshake failure') extends RuntimeException
    {
        public function getHandlerContext(): array
        {
            return ['errno' => 60];
        }
    };

    $error = UptimeTransportError::fromThrowable($exception);

    expect($error['type'])->toBe(UptimeTransportErrorType::Tls)
        ->and($error['code'])->toBe(60);
});

test('extracts curl errno from previous handler context', function () {
    $previous = new class('Handler reported connection failure') extends RuntimeException
    {
        public function getHandlerContext(): array
        {
            return ['curl_errno' => 7];
        }
    };

    $error = UptimeTransportError::fromThrowable(new ConnectionException('Connection failed', 0, $previous));

    expect($error['type'])->toBe(UptimeTransportErrorType::Connection)
        ->and($error['code'])->toBe(7);
});

test('formats transport error labels colors and summaries from enum or persisted value', function () {
    expect(UptimeTransportError::label(UptimeTransportErrorType::Dns))->toBe('DNS failure')
        ->and(UptimeTransportError::label('tls'))->toBe('TLS/SSL failure')
        ->and(UptimeTransportError::label('not-a-real-type'))->toBe('-')
        ->and(UptimeTransportError::color(UptimeTransportErrorType::Timeout))->toBe('warning')
        ->and(UptimeTransportError::color('connection'))->toBe('danger')
        ->and(UptimeTransportError::summary('connection'))->toBe('Website heartbeat failed before an HTTP response: the connection could not be established.')
        ->and(UptimeTransportError::summary('dns', 'API heartbeat'))->toBe('API heartbeat failed before an HTTP response: DNS lookup failed.')
        ->and(UptimeTransportError::summary(UptimeTransportErrorType::Unknown))->toBe('Website heartbeat failed before an HTTP response because of a transport error.');
});

test('truncates long transport messages before persistence', function () {
    $error = UptimeTransportError::fromThrowable(new ConnectionException(str_repeat('x', 1200)));

    expect(mb_strlen($error['message']))->toBeLessThanOrEqual(1003)
        ->and($error['message'])->toEndWith('...');
});
