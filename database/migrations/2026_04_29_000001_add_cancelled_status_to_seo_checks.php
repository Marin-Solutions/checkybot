<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::statement("ALTER TABLE seo_checks MODIFY status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'"),
            'pgsql' => $this->replacePostgresStatusConstraint(['pending', 'running', 'completed', 'failed', 'cancelled']),
            'sqlite' => $this->replaceSqliteStatusColumn(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'mysql' => $this->rollbackMysqlStatus(),
            'pgsql' => $this->rollbackPostgresStatus(),
            'sqlite' => $this->replaceSqliteStatusColumn(),
            default => null,
        };
    }

    private function rollbackMysqlStatus(): void
    {
        // Rollbacks cannot preserve a status value that no longer exists in the old enum.
        DB::statement("UPDATE seo_checks SET status = 'failed' WHERE status = 'cancelled'");
        DB::statement("ALTER TABLE seo_checks MODIFY status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending'");
    }

    private function rollbackPostgresStatus(): void
    {
        // Rollbacks cannot preserve a status value that no longer exists in the old check constraint.
        DB::statement("UPDATE seo_checks SET status = 'failed' WHERE status = 'cancelled'");
        $this->replacePostgresStatusConstraint(['pending', 'running', 'completed', 'failed']);
    }

    private function replacePostgresStatusConstraint(array $statuses): void
    {
        $quotedStatuses = collect($statuses)
            ->map(fn (string $status): string => "'{$status}'")
            ->implode(', ');

        DB::statement('ALTER TABLE seo_checks DROP CONSTRAINT IF EXISTS seo_checks_status_check');
        DB::statement("ALTER TABLE seo_checks ADD CONSTRAINT seo_checks_status_check CHECK (status::text = ANY (ARRAY[{$quotedStatuses}]::text[]))");
    }

    private function replaceSqliteStatusColumn(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }
};
