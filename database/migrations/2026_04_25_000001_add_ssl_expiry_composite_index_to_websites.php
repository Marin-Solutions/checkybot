<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if (! $this->indexExists('websites', 'websites_created_by_ssl_check_ssl_expiry_idx')) {
                $table->index(
                    ['created_by', 'ssl_check', 'ssl_expiry_date'],
                    'websites_created_by_ssl_check_ssl_expiry_idx'
                );
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if ($this->indexExists('websites', 'websites_created_by_ssl_check_ssl_expiry_idx')) {
                $table->dropIndex('websites_created_by_ssl_check_ssl_expiry_idx');
            }
        });
    }
};
