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
            $table->longText('html_content')->nullable()->after('html_size_bytes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_crawl_results', function (Blueprint $table) {
            $table->dropColumn('html_content');
        });
    }
};
