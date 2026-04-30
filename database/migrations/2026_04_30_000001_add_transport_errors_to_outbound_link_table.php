<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_link', function (Blueprint $table) {
            $table->string('transport_error_type', 40)->nullable()->after('http_status_code');
            $table->text('transport_error_message')->nullable()->after('transport_error_type');
            $table->integer('transport_error_code')->nullable()->after('transport_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_link', function (Blueprint $table) {
            $table->dropColumn([
                'transport_error_type',
                'transport_error_message',
                'transport_error_code',
            ]);
        });
    }
};
