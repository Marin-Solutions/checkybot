<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('identity_endpoint')->nullable()->after('technology');
            $table->unique(['created_by', 'environment', 'identity_endpoint'], 'projects_owner_environment_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_owner_environment_identity_unique');
            $table->dropColumn('identity_endpoint');
        });
    }
};
