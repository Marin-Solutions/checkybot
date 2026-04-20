<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('package_key')->nullable()->after('name');
            $table->string('base_url', 1000)->nullable()->after('identity_endpoint');
            $table->string('repository')->nullable()->after('base_url');
            $table->json('sync_defaults')->nullable()->after('repository');
            $table->timestamp('last_synced_at')->nullable()->after('sync_defaults');

            $table->unique(['created_by', 'environment', 'package_key'], 'projects_owner_environment_package_key_unique');
        });

        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropUnique(['url']);
            $table->string('http_method', 10)->default('GET')->after('url');
            $table->string('request_path', 1000)->nullable()->after('http_method');
            $table->unsignedSmallInteger('expected_status')->default(200)->after('headers');
            $table->unsignedSmallInteger('timeout_seconds')->nullable()->after('expected_status');
            $table->string('package_schedule', 100)->nullable()->after('timeout_seconds');
            $table->boolean('is_enabled')->default(true)->after('package_schedule');
            $table->timestamp('last_synced_at')->nullable()->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_apis', function (Blueprint $table) {
            $table->dropColumn([
                'http_method',
                'request_path',
                'expected_status',
                'timeout_seconds',
                'package_schedule',
                'is_enabled',
                'last_synced_at',
            ]);

            $table->unique('url');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_owner_environment_package_key_unique');
            $table->dropColumn([
                'package_key',
                'base_url',
                'repository',
                'sync_defaults',
                'last_synced_at',
            ]);
        });
    }
};
