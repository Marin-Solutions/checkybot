<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_log_history', function (Blueprint $table): void {
            if (! Schema::hasIndex('website_log_history', 'wlh_website_created_id_ssl_idx')) {
                $table->index(
                    ['website_id', 'created_at', 'id', 'ssl_expiry_date'],
                    'wlh_website_created_id_ssl_idx'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('website_log_history', function (Blueprint $table): void {
            if (Schema::hasIndex('website_log_history', 'wlh_website_created_id_ssl_idx')) {
                $table->dropIndex('wlh_website_created_id_ssl_idx');
            }
        });
    }
};
