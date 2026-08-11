<?php

use App\Models\ProjectComponent;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('heartbeat index repair migration restores indexes on a partially migrated table', function () {
    Schema::table('project_component_heartbeats', function (Blueprint $table): void {
        $table->dropUnique('project_component_heartbeats_project_component_id_idempotency_key_unique');
        $table->dropIndex('project_component_heartbeats_project_component_id_observed_at_index');
    });

    expect(Schema::hasIndex('project_component_heartbeats', ['project_component_id', 'observed_at']))->toBeFalse()
        ->and(Schema::hasIndex('project_component_heartbeats', ['project_component_id', 'idempotency_key']))->toBeFalse();

    $migration = require database_path('migrations/2026_08_11_000001_add_missing_project_component_heartbeat_indexes.php');
    $migration->up();

    $indexes = collect(Schema::getIndexes('project_component_heartbeats'));
    $observedAtIndex = $indexes->first(
        fn (array $index): bool => $index['columns'] === ['project_component_id', 'observed_at']
    );
    $idempotencyIndex = $indexes->first(
        fn (array $index): bool => $index['columns'] === ['project_component_id', 'idempotency_key']
    );

    expect($observedAtIndex)->not->toBeNull()
        ->and($idempotencyIndex)->not->toBeNull();

    expect($observedAtIndex['columns'])->toBe(['project_component_id', 'observed_at'])
        ->and($observedAtIndex['unique'])->toBeFalse()
        ->and(strlen($observedAtIndex['name']))->toBeLessThanOrEqual(64)
        ->and($idempotencyIndex['columns'])->toBe(['project_component_id', 'idempotency_key'])
        ->and($idempotencyIndex['unique'])->toBeTrue()
        ->and(strlen($idempotencyIndex['name']))->toBeLessThanOrEqual(64);

    [$firstComponent, $secondComponent] = ProjectComponent::factory()->count(2)->create();
    $heartbeat = [
        'status' => 'healthy',
        'event' => 'status',
        'summary' => 'Component is healthy.',
        'observed_at' => now(),
        'idempotency_key' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('project_component_heartbeats')->insert([
        'project_component_id' => $firstComponent->id,
        'component_name' => $firstComponent->name,
        ...$heartbeat,
    ]);

    DB::table('project_component_heartbeats')->insert([
        'project_component_id' => $secondComponent->id,
        'component_name' => $secondComponent->name,
        ...$heartbeat,
    ]);

    expect(fn () => DB::table('project_component_heartbeats')->insert([
        'project_component_id' => $firstComponent->id,
        'component_name' => $firstComponent->name,
        ...$heartbeat,
    ]))
        ->toThrow(QueryException::class);
});
