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
            'mysql', 'mariadb' => DB::statement('ALTER TABLE `server_information_history` MODIFY `cpu_load` TEXT NOT NULL'),
            default => Schema::table('server_information_history', function (Blueprint $table): void {
                $table->text('cpu_load')->change();
            }),
        };
    }

    public function down(): void
    {
        //
    }

    private function cpuLoadColumnIsAlreadyText(): bool
    {
        return in_array(Schema::getColumnType('server_information_history', 'cpu_load'), [
            'string',
            'text',
        ], true);
    }
};
