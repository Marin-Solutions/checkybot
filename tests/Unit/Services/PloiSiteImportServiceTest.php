<?php

namespace Tests\Unit\Services;

use App\Models\PloiAccounts;
use App\Models\PloiServers;
use App\Models\PloiWebsites;
use App\Models\User;
use App\Services\PloiSiteImportService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PloiSiteImportServiceTest extends TestCase
{
    protected User $user;

    protected PloiAccounts $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsUser();
        $this->account = PloiAccounts::factory()->create([
            'created_by' => $this->user->id,
            'key' => 'test-api-key-123',
        ]);
    }

    public function test_imports_sites_from_all_servers(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/123/sites*' => Http::response([
                'data' => [
                    [
                        'id' => 456,
                        'status' => 'active',
                        'domain' => 'example.com',
                        'deploy_script' => true,
                        'web_directory' => '/public',
                        'project_type' => 'laravel',
                        'project_root' => '/var/www',
                        'last_deploy_at' => '2025-01-01 12:00:00',
                        'system_user' => 'ploi',
                        'php_version' => '8.3',
                        'health_url' => 'https://example.com/health',
                        'notification_urls' => ['https://example.com/notify'],
                        'has_repository' => true,
                        'created_at' => '2024-01-01 12:00:00',
                    ],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);

        $service = new PloiSiteImportService($this->account);
        $imported = $service->import();

        $this->assertEquals(1, $imported);
        $this->assertDatabaseHas('ploi_websites', [
            'site_id' => 456,
            'server_id' => 123,
            'domain' => 'example.com',
            'ploi_account_id' => $this->account->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_imports_sites_from_specific_server(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/123/sites*' => Http::response([
                'data' => [
                    [
                        'id' => 789,
                        'status' => 'active',
                        'domain' => 'test.com',
                    ],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);

        $service = new PloiSiteImportService($this->account);
        $imported = $service->import($server);

        $this->assertEquals(1, $imported);
        $this->assertDatabaseHas('ploi_websites', [
            'site_id' => 789,
            'server_id' => 123,
            'domain' => 'test.com',
        ]);
    }

    public function test_handles_pagination_correctly(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/123/sites?per_page=50&page=1' => Http::response([
                'data' => [
                    ['id' => 1, 'status' => 'active', 'domain' => 'site1.com'],
                    ['id' => 2, 'status' => 'active', 'domain' => 'site2.com'],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 2,
                ],
            ], 200),
            'ploi.io/api/servers/123/sites?per_page=50&page=2' => Http::response([
                'data' => [
                    ['id' => 3, 'status' => 'active', 'domain' => 'site3.com'],
                ],
                'meta' => [
                    'current_page' => 2,
                    'last_page' => 2,
                ],
            ], 200),
        ]);

        $service = new PloiSiteImportService($this->account);
        $imported = $service->import($server);

        $this->assertEquals(3, $imported);
        $this->assertDatabaseHas('ploi_websites', ['site_id' => 1]);
        $this->assertDatabaseHas('ploi_websites', ['site_id' => 2]);
        $this->assertDatabaseHas('ploi_websites', ['site_id' => 3]);
    }

    public function test_updates_existing_sites(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        PloiWebsites::create([
            'site_id' => 456,
            'server_id' => 123,
            'domain' => 'old-domain.com',
            'status' => 'inactive',
            'ploi_account_id' => $this->account->id,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/123/sites*' => Http::response([
                'data' => [
                    [
                        'id' => 456,
                        'status' => 'active',
                        'domain' => 'new-domain.com',
                    ],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);

        $service = new PloiSiteImportService($this->account);
        $imported = $service->import($server);

        $this->assertEquals(1, $imported);
        $this->assertDatabaseHas('ploi_websites', [
            'site_id' => 456,
            'domain' => 'new-domain.com',
            'status' => 'active',
        ]);
    }

    public function test_throws_exception_on_api_failure(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/123/sites*' => Http::response([
                'error' => 'Unauthorized',
            ], 401),
        ]);

        $service = new PloiSiteImportService($this->account);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to fetch sites for server 123');

        $service->import($server);
    }

    public function test_uses_correct_api_token(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        Http::fake();

        $service = new PloiSiteImportService($this->account);
        $service->import($server);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-api-key-123');
        });
    }

    public function test_imports_sites_from_multiple_servers(): void
    {
        $server1 = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 100,
            'created_by' => $this->user->id,
        ]);

        $server2 = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 200,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/100/sites*' => Http::response([
                'data' => [
                    ['id' => 1, 'status' => 'active', 'domain' => 'server1-site.com'],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200),
            'ploi.io/api/servers/200/sites*' => Http::response([
                'data' => [
                    ['id' => 2, 'status' => 'active', 'domain' => 'server2-site.com'],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);

        $service = new PloiSiteImportService($this->account);
        $imported = $service->import();

        $this->assertEquals(2, $imported);
        $this->assertDatabaseHas('ploi_websites', ['site_id' => 1, 'server_id' => 100]);
        $this->assertDatabaseHas('ploi_websites', ['site_id' => 2, 'server_id' => 200]);
    }

    public function test_handles_empty_response_data(): void
    {
        $server = PloiServers::factory()->create([
            'ploi_account_id' => $this->account->id,
            'server_id' => 123,
            'created_by' => $this->user->id,
        ]);

        Http::fake([
            'ploi.io/api/servers/123/sites*' => Http::response([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200),
        ]);

        $service = new PloiSiteImportService($this->account);
        $imported = $service->import($server);

        $this->assertEquals(0, $imported);
    }
}
