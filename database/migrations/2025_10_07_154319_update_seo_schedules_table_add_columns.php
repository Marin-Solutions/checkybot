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
        Schema::table('seo_schedules', function (Blueprint $table) {
            // Add missing columns if they don't exist
            if (! Schema::hasColumn('seo_schedules', 'website_id')) {
                $table->foreignId('website_id')->constrained()->onDelete('cascade');
            }
            if (! Schema::hasColumn('seo_schedules', 'frequency')) {
                $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('daily');
            }
            if (! Schema::hasColumn('seo_schedules', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable();
            }
            if (! Schema::hasColumn('seo_schedules', 'next_run_at')) {
                $table->timestamp('next_run_at')->nullable();
            }
            if (! Schema::hasColumn('seo_schedules', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('seo_schedules', 'website_id')) {
                $table->dropForeign(['website_id']);
                $table->dropColumn('website_id');
            }
            if (Schema::hasColumn('seo_schedules', 'frequency')) {
                $table->dropColumn('frequency');
            }
            if (Schema::hasColumn('seo_schedules', 'last_run_at')) {
                $table->dropColumn('last_run_at');
            }
            if (Schema::hasColumn('seo_schedules', 'next_run_at')) {
                $table->dropColumn('next_run_at');
            }
            if (Schema::hasColumn('seo_schedules', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
