<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_rules', function (Blueprint $table) {
            $table->string('last_evaluation_status')->nullable()->after('last_evaluated_at');
            $table->text('last_evaluation_reason')->nullable()->after('last_evaluation_status');
            $table->timestamp('last_reported_at')->nullable()->after('last_evaluation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('server_rules', function (Blueprint $table) {
            $table->dropColumn([
                'last_evaluation_status',
                'last_evaluation_reason',
                'last_reported_at',
            ]);
        });
    }
};
