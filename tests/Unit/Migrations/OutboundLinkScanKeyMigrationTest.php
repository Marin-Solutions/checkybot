<?php

use App\Models\OutboundLink;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('outbound link scan key migration collapses duplicates before adding unique index', function () {
    $migrationPath = database_path('migrations/2026_04_30_000002_add_unique_outbound_link_scan_key.php');
    $migration = require $migrationPath;

    Schema::table('outbound_link', function (Blueprint $table) {
        if (Schema::hasIndex('outbound_link', 'outbound_link_website_found_outgoing_unique')) {
            $table->dropUnique('outbound_link_website_found_outgoing_unique');
        }
    });

    $website = Website::factory()->create();

    $olderLinkId = DB::table('outbound_link')->insertGetId([
        'website_id' => $website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 404,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'last_checked_at' => now()->subDay(),
    ]);

    $newerLinkId = DB::table('outbound_link')->insertGetId([
        'website_id' => $website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 200,
        'created_at' => now(),
        'updated_at' => now(),
        'last_checked_at' => now(),
    ]);

    $migration->up();

    assertDatabaseMissing('outbound_link', [
        'id' => $olderLinkId,
    ]);

    assertDatabaseHas('outbound_link', [
        'id' => $newerLinkId,
        'website_id' => $website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
        'http_status_code' => 200,
    ]);

    expect(fn () => OutboundLink::factory()->create([
        'website_id' => $website->id,
        'found_on' => 'https://example.com/source',
        'outgoing_url' => 'https://external.com/page',
    ]))->toThrow(QueryException::class);
});
