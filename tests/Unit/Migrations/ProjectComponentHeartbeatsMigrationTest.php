<?php

use Illuminate\Support\Facades\Schema;

test('project component heartbeats migration skips creation when table already exists', function () {
    expect(Schema::hasTable('project_component_heartbeats'))->toBeTrue();

    $migration = require database_path('migrations/2026_03_21_194801_create_project_component_heartbeats_table.php');

    $migration->up();

    expect(Schema::hasTable('project_component_heartbeats'))->toBeTrue();
});
