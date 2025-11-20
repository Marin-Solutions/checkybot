<?php

use App\Models\PloiServers;
use App\Models\User;
use App\Services\PloiServerImportService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = $this->actingAsUser();
});

test('imports servers from ploi api', function () {
    Http::fake([
        'ploi.io/api/servers*' => Http::response([
            'data' => [
                [
                    'id' => 123,
                    'type' => 'app',
                    'name' => 'Production Server',
                    'ip_address' => '192.168.1.1',
                    'php_version' => '8.3',
                    'mysql_version' => '8.0',
                    'sites_count' => 5,
                    'status' => 'active',
                    'status_id' => 1,
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiServerImportService('test-api-key', $this->user->id, 1);
    $imported = $service->import();

    expect($imported)->toBe(1);
    assertDatabaseHas('ploi_servers', [
        'server_id' => 123,
        'name' => 'Production Server',
        'ip_address' => '192.168.1.1',
        'created_by' => $this->user->id,
        'ploi_account_id' => 1,
    ]);
});

test('handles pagination correctly', function () {
    Http::fake([
        'ploi.io/api/servers?per_page=50&page=1' => Http::response([
            'data' => [
                [
                    'id' => 1,
                    'type' => 'app',
                    'name' => 'Server 1',
                    'ip_address' => '10.0.0.1',
                    'php_version' => '8.3',
                    'mysql_version' => '8.0',
                    'sites_count' => 1,
                    'status' => 'active',
                    'status_id' => 1,
                ],
                [
                    'id' => 2,
                    'type' => 'app',
                    'name' => 'Server 2',
                    'ip_address' => '10.0.0.2',
                    'php_version' => '8.2',
                    'mysql_version' => '8.0',
                    'sites_count' => 2,
                    'status' => 'active',
                    'status_id' => 1,
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 2,
            ],
        ], 200),
        'ploi.io/api/servers?per_page=50&page=2' => Http::response([
            'data' => [
                [
                    'id' => 3,
                    'type' => 'db',
                    'name' => 'Server 3',
                    'ip_address' => '10.0.0.3',
                    'php_version' => '8.1',
                    'mysql_version' => '8.0',
                    'sites_count' => 0,
                    'status' => 'active',
                    'status_id' => 1,
                ],
            ],
            'meta' => [
                'current_page' => 2,
                'last_page' => 2,
            ],
        ], 200),
    ]);

    $service = new PloiServerImportService('test-api-key', $this->user->id, 1);
    $imported = $service->import();

    expect($imported)->toBe(3);
    assertDatabaseHas('ploi_servers', ['server_id' => 1, 'name' => 'Server 1']);
    assertDatabaseHas('ploi_servers', ['server_id' => 2, 'name' => 'Server 2']);
    assertDatabaseHas('ploi_servers', ['server_id' => 3, 'name' => 'Server 3']);
});

test('updates existing servers', function () {
    PloiServers::create([
        'server_id' => 123,
        'type' => 'app',
        'name' => 'Old Name',
        'ip_address' => '192.168.1.1',
        'php_version' => '8.2',
        'mysql_version' => '8.0',
        'sites_count' => 3,
        'status' => 'inactive',
        'status_id' => 0,
        'created_by' => $this->user->id,
        'ploi_account_id' => 1,
    ]);

    Http::fake([
        'ploi.io/api/servers*' => Http::response([
            'data' => [
                [
                    'id' => 123,
                    'type' => 'app',
                    'name' => 'Updated Name',
                    'ip_address' => '192.168.1.1',
                    'php_version' => '8.3',
                    'mysql_version' => '8.0',
                    'sites_count' => 5,
                    'status' => 'active',
                    'status_id' => 1,
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiServerImportService('test-api-key', $this->user->id, 1);
    $imported = $service->import();

    expect($imported)->toBe(1);
    assertDatabaseHas('ploi_servers', [
        'server_id' => 123,
        'name' => 'Updated Name',
        'php_version' => '8.3',
        'sites_count' => 5,
        'status' => 'active',
    ]);
});

test('throws exception on api failure', function () {
    Http::fake([
        'ploi.io/api/servers*' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $service = new PloiServerImportService('invalid-key', $this->user->id, 1);

    $service->import();
})->throws(\Exception::class, 'Failed to fetch servers');

test('uses correct api token', function () {
    Http::fake([
        'ploi.io/api/servers*' => Http::response([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiServerImportService('my-secret-token', $this->user->id, 1);
    $service->import();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer my-secret-token');
    });
});

test('handles empty response data', function () {
    Http::fake([
        'ploi.io/api/servers*' => Http::response([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiServerImportService('test-api-key', $this->user->id, 1);
    $imported = $service->import();

    expect($imported)->toBe(0);
});

test('sets correct ploi account id', function () {
    Http::fake([
        'ploi.io/api/servers*' => Http::response([
            'data' => [
                [
                    'id' => 999,
                    'type' => 'app',
                    'name' => 'Test Server',
                    'ip_address' => '1.2.3.4',
                    'php_version' => '8.3',
                    'mysql_version' => '8.0',
                    'sites_count' => 0,
                    'status' => 'active',
                    'status_id' => 1,
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiServerImportService('test-api-key', $this->user->id, 42);
    $service->import();

    assertDatabaseHas('ploi_servers', [
        'server_id' => 999,
        'ploi_account_id' => 42,
        'created_by' => $this->user->id,
    ]);
});
