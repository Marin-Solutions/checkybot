<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('server_information_history') || ! Schema::hasColumn('server_information_history', 'cpu_load')) {
            return;
        }

        if ($this->cpuLoadColumnIsAlreadyText()) {
            return;
        }

        match (Schema::getConnection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement(sprintf(
                'ALTER TABLE %s MODIFY %s TEXT NOT NULL',
                DB::getQueryGrammar()->wrapTable('server_information_history'),
                DB::getQueryGrammar()->wrap('cpu_load'),
            )),
            default => Schema::table('server_information_history', function (Blueprint $table): void {
                $table->text('cpu_load')->change();
            }),
        };

        if (! $this->cpuLoadColumnIsAlreadyText()) {
            throw new RuntimeException('Unable to widen server_information_history.cpu_load to text.');
        }
    }

    public function down(): void
    {
        // Intentionally keep cpu_load widened so production reporter samples cannot overflow.
    }

    private function cpuLoadColumnIsAlreadyText(): bool
    {
        return in_array(strtolower(Schema::getColumnType('server_information_history', 'cpu_load')), [
            'character varying',
            'longtext',
            'mediumtext',
            'string',
            'text',
            'tinytext',
            'varchar',
        ], true);
    }
};
