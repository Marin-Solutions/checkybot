<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_rules', function (Blueprint $table) {
            $table->decimal('last_evaluated_value', 8, 2)->nullable()->after('recovered_at');
            $table->timestamp('last_evaluated_at')->nullable()->after('last_evaluated_value');
        });
    }

    public function down(): void
    {
        Schema::table('server_rules', function (Blueprint $table) {
            $table->dropColumn([
                'last_evaluated_value',
                'last_evaluated_at',
            ]);
        });
    }
};
