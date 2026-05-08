<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_components', function (Blueprint $table): void {
            $table->timestamp('silenced_until')->nullable()->after('archive_reason');
            $table->index('silenced_until');
        });
    }

    public function down(): void
    {
        Schema::table('project_components', function (Blueprint $table): void {
            $table->dropIndex(['silenced_until']);
            $table->dropColumn('silenced_until');
        });
    }
};
