<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_rules', function (Blueprint $table) {
            $table->boolean('is_triggered')->default(false)->after('is_active');
            $table->timestamp('triggered_at')->nullable()->after('is_triggered');
            $table->timestamp('recovered_at')->nullable()->after('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('server_rules', function (Blueprint $table) {
            $table->dropColumn([
                'is_triggered',
                'triggered_at',
                'recovered_at',
            ]);
        });
    }
};
