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
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->text('failure_summary')->nullable()->after('crawl_summary');
            $table->json('failure_context')->nullable()->after('failure_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->dropColumn(['failure_summary', 'failure_context']);
        });
    }
};
