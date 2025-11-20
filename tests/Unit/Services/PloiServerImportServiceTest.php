<?php

namespace Tests\Unit\Services;

use App\Models\PloiServers;
use App\Models\User;
use App\Services\PloiServerImportService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PloiServerImportServiceTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsUser();
    }

    public function test_imports_servers_from_ploi_api(): void
    {
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

        $this->assertEquals(1, $imported);
        $this->assertDatabaseHas('ploi_servers', [
            'server_id' => 123,
            'name' => 'Production Server',
            'ip_address' => '192.168.1.1',
            'created_by' => $this->user->id,
            'ploi_account_id' => 1,
        ]);
    }

    public function test_handles_pagination_correctly(): void
    {
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

        $this->assertEquals(3, $imported);
        $this->assertDatabaseHas('ploi_servers', ['server_id' => 1, 'name' => 'Server 1']);
        $this->assertDatabaseHas('ploi_servers', ['server_id' => 2, 'name' => 'Server 2']);
        $this->assertDatabaseHas('ploi_servers', ['server_id' => 3, 'name' => 'Server 3']);
    }

    public function test_updates_existing_servers(): void
    {
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

        $this->assertEquals(1, $imported);
        $this->assertDatabaseHas('ploi_servers', [
            'server_id' => 123,
            'name' => 'Updated Name',
            'php_version' => '8.3',
            'sites_count' => 5,
            'status' => 'active',
        ]);
    }

    public function test_throws_exception_on_api_failure(): void
    {
        Http::fake([
            'ploi.io/api/servers*' => Http::response([
                'error' => 'Unauthorized',
            ], 401),
        ]);

        $service = new PloiServerImportService('invalid-key', $this->user->id, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to fetch servers');

        $service->import();
    }

    public function test_uses_correct_api_token(): void
    {
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
    }

    public function test_handles_empty_response_data(): void
    {
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

        $this->assertEquals(0, $imported);
    }

    public function test_sets_correct_ploi_account_id(): void
    {
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

        $this->assertDatabaseHas('ploi_servers', [
            'server_id' => 999,
            'ploi_account_id' => 42,
            'created_by' => $this->user->id,
        ]);
    }
}
