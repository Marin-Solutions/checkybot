<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->json('request_headers')->nullable()->after('summary');
            $table->json('response_headers')->nullable()->after('request_headers');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_api_results', function (Blueprint $table) {
            $table->dropColumn(['request_headers', 'response_headers']);
        });
    }
};
