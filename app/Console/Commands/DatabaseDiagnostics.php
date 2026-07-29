<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DatabaseDiagnostics extends Command
{
    protected $signature = 'app:database-diagnostics
        {--json : Output the diagnostic result as JSON}';

    protected $description = 'Inspect database availability and optional MySQL statement telemetry';

    public function handle(DatabaseManager $databases): int
    {
        $connection = $databases->connection();

        try {
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $connection->select('SELECT 1');

                return $this->displayResult([
                    'database' => 'available',
                    'statement_diagnostics' => [
                        'status' => 'unavailable',
                        'reason' => 'unsupported_database_driver',
                        'statements' => [],
                    ],
                ]);
            }

            $statements = $this->statementDiagnostics($connection);
        } catch (QueryException $exception) {
            if ($this->isPerformanceSchemaSelectDenied($exception)) {
                return $this->displayResult([
                    'database' => 'available',
                    'statement_diagnostics' => [
                        'status' => 'unavailable',
                        'reason' => 'performance_schema_select_denied',
                        'statements' => [],
                    ],
                ]);
            }

            Log::error('Database diagnostics failed.', [
                'connection' => $exception->getConnectionName(),
                'exception' => $exception,
            ]);

            return $this->displayFailure();
        }

        return $this->displayResult([
            'database' => 'available',
            'statement_diagnostics' => [
                'status' => 'available',
                'reason' => null,
                'statements' => array_map(
                    static fn (object|array $statement): array => (array) $statement,
                    $statements,
                ),
            ],
        ]);
    }

    /**
     * @return array<int, object|array<string, mixed>>
     */
    private function statementDiagnostics(Connection $connection): array
    {
        return $connection->select(
            'SELECT
                LEFT(DIGEST_TEXT, 300) AS pattern,
                COUNT_STAR AS executions,
                ROUND(SUM_TIMER_WAIT / 1000000000000, 3) AS total_seconds,
                ROUND(AVG_TIMER_WAIT / 1000000000, 3) AS average_ms,
                ROUND(MAX_TIMER_WAIT / 1000000000000, 3) AS maximum_seconds,
                SUM_ROWS_EXAMINED AS rows_examined,
                SUM_ROWS_SENT AS rows_sent,
                SUM_NO_INDEX_USED AS no_index
            FROM performance_schema.events_statements_summary_by_digest
            WHERE SCHEMA_NAME = DATABASE()
                AND DIGEST_TEXT IS NOT NULL
            ORDER BY SUM_TIMER_WAIT DESC
            LIMIT 40',
        );
    }

    private function isPerformanceSchemaSelectDenied(QueryException $exception): bool
    {
        $driverErrorCode = $exception->getPrevious()?->errorInfo[1] ?? null;

        return (int) $driverErrorCode === 1142
            && str_contains(
                strtolower($exception->getSql()),
                'performance_schema.events_statements_summary_by_digest',
            );
    }

    private function displayFailure(): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'database' => 'unknown',
                'statement_diagnostics' => [
                    'status' => 'error',
                    'reason' => 'database_query_failed',
                    'statements' => [],
                ],
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->error('Database diagnostics failed. Inspect the application log for details.');
        }

        return self::FAILURE;
    }

    /**
     * @param  array{
     *     database: string,
     *     statement_diagnostics: array{
     *         status: string,
     *         reason: string|null,
     *         statements: array<int, array<string, mixed>>
     *     }
     * }  $result
     */
    private function displayResult(array $result): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Database: available');

        if ($result['statement_diagnostics']['status'] === 'unavailable') {
            $reason = match ($result['statement_diagnostics']['reason']) {
                'performance_schema_select_denied' => 'performance_schema SELECT permission denied',
                'unsupported_database_driver' => 'database driver does not support MySQL statement telemetry',
                default => 'reason unavailable',
            };

            $this->warn("Statement diagnostics: unavailable ({$reason})");

            return self::SUCCESS;
        }

        $this->info('Statement diagnostics: available');
        $this->table(
            ['Pattern', 'Executions', 'Total seconds', 'Average ms', 'Maximum seconds', 'Rows examined', 'Rows sent', 'No index'],
            $result['statement_diagnostics']['statements'],
        );

        return self::SUCCESS;
    }
}
