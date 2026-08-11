<?php

use App\Models\ProjectComponent;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('status observation migration creates heartbeat indexes with short names', function () {
    Schema::drop('project_component_heartbeats');

    $migration = require database_path('migrations/2026_07_31_000001_restore_component_status_observations.php');
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

    expect($observedAtIndex['unique'])->toBeFalse()
        ->and(strlen($observedAtIndex['name']))->toBeLessThanOrEqual(64)
        ->and($idempotencyIndex['unique'])->toBeTrue()
        ->and(strlen($idempotencyIndex['name']))->toBeLessThanOrEqual(64);
});

test('heartbeat index repair migration restores indexes on a partially migrated table', function () {
    recreateProjectComponentHeartbeatsTable();

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

test('heartbeat index repair migration preserves equivalent indexes with alternate names', function () {
    recreateProjectComponentHeartbeatsTable('pch_existing_observed_idx', 'pch_existing_idempotency_uniq');

    expect(Schema::hasIndex(
        'project_component_heartbeats',
        'pch_existing_observed_idx'
    ))->toBeTrue()
        ->and(Schema::hasIndex(
            'project_component_heartbeats',
            'pch_existing_idempotency_uniq'
        ))->toBeTrue()
        ->and(Schema::hasIndex('project_component_heartbeats', 'pch_component_observed_idx'))->toBeFalse()
        ->and(Schema::hasIndex('project_component_heartbeats', 'pch_component_idempotency_uniq'))->toBeFalse();

    $indexesBefore = Schema::getIndexes('project_component_heartbeats');
    $migration = require database_path('migrations/2026_08_11_000001_add_missing_project_component_heartbeat_indexes.php');

    $migration->up();

    expect(Schema::getIndexes('project_component_heartbeats'))->toBe($indexesBefore);
});

function recreateProjectComponentHeartbeatsTable(
    ?string $observedAtIndex = null,
    ?string $idempotencyIndex = null
): void {
    Schema::drop('project_component_heartbeats');

    $migration = require database_path('migrations/2026_07_31_000001_restore_component_status_observations.php');
    $migration->up();

    Schema::table('project_component_heartbeats', function (Blueprint $table) use (
        $observedAtIndex,
        $idempotencyIndex
    ): void {
        $table->dropUnique('pch_component_idempotency_uniq');
        $table->dropIndex('pch_component_observed_idx');

        if ($observedAtIndex !== null) {
            $table->index(['project_component_id', 'observed_at'], $observedAtIndex);
        }

        if ($idempotencyIndex !== null) {
            $table->unique(['project_component_id', 'idempotency_key'], $idempotencyIndex);
        }
    });
}
