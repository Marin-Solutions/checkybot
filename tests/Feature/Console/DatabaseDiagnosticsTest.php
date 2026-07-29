<?php

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

function databaseDiagnosticsQueryException(int $driverErrorCode, string $sql): QueryException
{
    $previous = new PDOException('Database operation failed.');
    $previous->errorInfo = ['42000', $driverErrorCode, 'Database operation failed.'];

    return new QueryException('mysql', $sql, [], $previous);
}

function useDatabaseDiagnosticsConnection(Connection $connection): void
{
    $databases = Mockery::mock(DatabaseManager::class);
    $databases->shouldReceive('connection')->once()->andReturn($connection);
    app()->instance(DatabaseManager::class, $databases);
}

test('database diagnostics returns available performance schema data', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection
        ->shouldReceive('select')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains(
            $sql,
            'performance_schema.events_statements_summary_by_digest',
        )))
        ->andReturn([
            (object) [
                'pattern' => 'select * from `websites` where `id` = ?',
                'executions' => 12,
                'total_seconds' => '0.450',
                'average_ms' => '37.500',
                'maximum_seconds' => '0.100',
                'rows_examined' => 12,
                'rows_sent' => 12,
                'no_index' => 0,
            ],
        ]);

    useDatabaseDiagnosticsConnection($connection);

    $this->artisan('app:database-diagnostics --json')
        ->expectsOutput(json_encode([
            'database' => 'available',
            'statement_diagnostics' => [
                'status' => 'available',
                'reason' => null,
                'statements' => [[
                    'pattern' => 'select * from `websites` where `id` = ?',
                    'executions' => 12,
                    'total_seconds' => '0.450',
                    'average_ms' => '37.500',
                    'maximum_seconds' => '0.100',
                    'rows_examined' => 12,
                    'rows_sent' => 12,
                    'no_index' => 0,
                ]],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

test('database diagnostics returns a reduced result for performance schema error 1142', function () {
    $sql = 'select * from performance_schema.events_statements_summary_by_digest';
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection
        ->shouldReceive('select')
        ->once()
        ->andThrow(databaseDiagnosticsQueryException(1142, $sql));

    useDatabaseDiagnosticsConnection($connection);

    $this->artisan('app:database-diagnostics --json')
        ->expectsOutput(json_encode([
            'database' => 'available',
            'statement_diagnostics' => [
                'status' => 'unavailable',
                'reason' => 'performance_schema_select_denied',
                'statements' => [],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

test('database diagnostics skips MySQL telemetry on other database drivers', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    $connection->shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['result' => 1]]);

    useDatabaseDiagnosticsConnection($connection);

    $this->artisan('app:database-diagnostics --json')
        ->expectsOutput(json_encode([
            'database' => 'available',
            'statement_diagnostics' => [
                'status' => 'unavailable',
                'reason' => 'unsupported_database_driver',
                'statements' => [],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

test('database diagnostics fails cleanly for unrelated database errors', function () {
    Log::spy();

    $sql = 'select * from performance_schema.events_statements_summary_by_digest';
    $exception = databaseDiagnosticsQueryException(2006, $sql);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('select')->once()->andThrow($exception);

    useDatabaseDiagnosticsConnection($connection);

    $this->artisan('app:database-diagnostics --json')
        ->expectsOutput(json_encode([
            'database' => 'unknown',
            'statement_diagnostics' => [
                'status' => 'error',
                'reason' => 'database_query_failed',
                'statements' => [],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertFailed();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Database diagnostics failed.'
            && $context['connection'] === 'mysql'
            && $context['exception'] === $exception);
});
