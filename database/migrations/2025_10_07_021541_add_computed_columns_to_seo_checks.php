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
            // Add computed columns for performance optimization
            $table->integer('computed_errors_count')->default(0)->after('total_crawlable_urls');
            $table->integer('computed_warnings_count')->default(0)->after('computed_errors_count');
            $table->integer('computed_notices_count')->default(0)->after('computed_warnings_count');
            $table->integer('computed_http_errors_count')->default(0)->after('computed_notices_count');
            $table->decimal('computed_health_score', 5, 2)->default(0)->after('computed_http_errors_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_checks', function (Blueprint $table) {
            $table->dropColumn([
                'computed_errors_count',
                'computed_warnings_count',
                'computed_notices_count',
                'computed_http_errors_count',
                'computed_health_score',
            ]);
        });
    }
};
