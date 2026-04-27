<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->string('request_body_type', 20)->nullable()->after('headers');
            $table->mediumText('request_body')->nullable()->after('request_body_type');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropColumn(['request_body_type', 'request_body']);
        });
    }
};
