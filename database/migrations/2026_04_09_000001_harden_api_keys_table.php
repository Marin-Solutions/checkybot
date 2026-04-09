<?php

use App\Models\ApiKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('key_hash', 64)->nullable()->after('key');
        });

        DB::table('api_keys')
            ->orderBy('id')
            ->get()
            ->each(function (object $record): void {
                if (! $record->key) {
                    return;
                }

                DB::table('api_keys')
                    ->where('id', $record->id)
                    ->update([
                        'key_hash' => ApiKey::hashKey($record->key),
                        'key' => ApiKey::maskKey($record->key),
                    ]);
            });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->unique('key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['key_hash']);
            $table->dropColumn('key_hash');
        });
    }
};
