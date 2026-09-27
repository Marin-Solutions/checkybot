<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checker_settings', function (Blueprint $table) {
            $table->string('setting_key', 64)->primary();
            $table->string('setting_value', 32);
            $table->timestamp('updated_at')->nullable();
        });

        $now = now();
        foreach ([
            'process_mode' => 'shadow',
            'uptime_owner' => 'laravel',
            'ssl_owner' => 'laravel',
            'api_owner' => 'laravel',
        ] as $key => $value) {
            DB::table('checker_settings')->insert([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => $now,
            ]);
        }

        Schema::create('checker_locks', function (Blueprint $table) {
            $table->string('lock_key', 191)->primary();
            $table->string('owner', 64);
            $table->dateTime('expires_at', 6)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('checker_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->index();
            $table->unsignedBigInteger('subject_id');
            $table->string('event', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->text('summary')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->dateTime('created_at', 6)->index();
            $table->dateTime('processed_at', 6)->nullable()->index();
        });

        Schema::create('checker_shadow_samples', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('laravel_status', 32)->nullable();
            $table->string('go_status', 32)->nullable();
            $table->string('match_result', 16);
            $table->string('detail', 500)->nullable();
            $table->dateTime('created_at', 6)->index();
            $table->unique(['kind', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checker_shadow_samples');
        Schema::dropIfExists('checker_outbox');
        Schema::dropIfExists('checker_locks');
        Schema::dropIfExists('checker_settings');
    }
};
