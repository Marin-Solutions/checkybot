<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Increase URL column length to handle long URLs (especially from GitHub)
            $table->string('url', 1000)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            // Revert URL column length back to original
            $table->string('url', 255)->change();
        });
    }
};
