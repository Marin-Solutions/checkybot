<?php

use App\Models\MonitorApis;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

test('monitor apis soft deletes repair migration restores drifted deleted at column', function () {
    $user = User::factory()->create();

    MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    Schema::table('monitor_apis', function (Blueprint $table) {
        if (Schema::hasIndex('monitor_apis', 'monitor_apis_source_deleted_package_interval_idx')) {
            $table->dropIndex('monitor_apis_source_deleted_package_interval_idx');
        }

        if (Schema::hasIndex('monitor_apis', 'monitor_apis_created_by_deleted_at_idx')) {
            $table->dropIndex('monitor_apis_created_by_deleted_at_idx');
        }
    });

    Schema::table('monitor_apis', function (Blueprint $table) {
        if (Schema::hasColumn('monitor_apis', 'deleted_at')) {
            $table->dropColumn('deleted_at');
        }
    });

    expect(Schema::hasColumn('monitor_apis', 'deleted_at'))->toBeFalse();

    $migrationPath = collect(glob(database_path('migrations/*_restore_monitor_apis_soft_deletes_column.php')))->first();

    expect($migrationPath)->not->toBeNull();

    $migration = require $migrationPath;
    $migration->up();

    expect(Schema::hasColumn('monitor_apis', 'deleted_at'))->toBeTrue();
    expect(MonitorApis::query()->where('created_by', $user->id)->count())->toBe(1);
});
