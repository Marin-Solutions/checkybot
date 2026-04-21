<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if ($this->indexExists('websites', 'websites_url_unique')) {
                $table->dropUnique('websites_url_unique');
            }

            if (! $this->indexExists('websites', 'websites_url_index')) {
                $table->index('url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if (! $this->indexExists('websites', 'websites_url_index')) {
                $table->index('url');
            }

            if (! $this->indexExists('websites', 'websites_url_unique')) {
                $table->unique('url');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }
};
