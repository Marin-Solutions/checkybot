<?php

namespace App\Services;

use App\Models\PloiAccounts;
use App\Models\PloiServers;
use App\Models\PloiWebsites;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PloiSiteImportService
{
    protected Model|PloiAccounts $account;

    protected int $userId;

    public function __construct(Model $account)
    {
        $this->account = $account;
        $this->userId = Auth::id();
    }

    /**
     * Import all sites from all servers of the account.
     *
     * @return int Number of imported/updated sites
     */
    public function import(?PloiServers $server = null): int
    {
        return $this->importWithSummary($server)['imported'];
    }

    /**
     * Import sites and return server-level outcome counts.
     *
     * @return array{
     *     imported: int,
     *     imported_servers: int,
     *     skipped_servers: int,
     *     failed_servers: int,
     *     failures: array<int, array{server_id: int|string|null, server_name: string|null, message: string}>
     * }
     */
    public function importWithSummary(?PloiServers $server = null): array
    {
        $imported = 0;
        $token = $this->account->key;
        $servers = $server ? collect([$server]) : PloiServers::where('ploi_account_id', $this->account->id)->get();
        $importedServers = 0;
        $skippedServers = 0;
        $failedServers = 0;
        $failures = [];

        foreach ($servers as $srv) {
            try {
                $sites = $this->fetchSitesForServer($srv, $token);
            } catch (Exception $exception) {
                $failedServers++;
                $failures[] = [
                    'server_id' => $srv->server_id,
                    'server_name' => $srv->name,
                    'message' => $exception->getMessage(),
                ];

                continue;
            }

            if ($sites->isEmpty()) {
                $skippedServers++;

                continue;
            }

            $importedServers++;

            foreach ($sites as $site) {
                PloiWebsites::updateOrCreate(
                    [
                        'site_id' => $site['id'],
                        'server_id' => $srv->server_id,
                        'created_by' => $this->userId,
                        'ploi_account_id' => $this->account->id,
                    ],
                    [
                        'status' => $site['status'] ?? null,
                        'domain' => $site['domain'] ?? null,
                        'deploy_script' => $site['deploy_script'] ?? false,
                        'web_directory' => $site['web_directory'] ?? null,
                        'project_type' => $site['project_type'] ?? null,
                        'project_root' => $site['project_root'] ?? null,
                        'last_deploy_at' => $site['last_deploy_at'] ?? null,
                        'system_user' => $site['system_user'] ?? null,
                        'php_version' => $site['php_version'] ?? null,
                        'health_url' => $site['health_url'] ?? null,
                        'notification_urls' => $site['notification_urls'] ?? null,
                        'has_repository' => $site['has_repository'] ?? false,
                        'site_created_at' => $site['created_at'] ?? null,
                    ]
                );
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'imported_servers' => $importedServers,
            'skipped_servers' => $skippedServers,
            'failed_servers' => $failedServers,
            'failures' => $failures,
        ];
    }

    /**
     * @param  array{
     *     imported: int,
     *     imported_servers: int,
     *     skipped_servers: int,
     *     failed_servers: int,
     *     failures: array<int, array{server_id: int|string|null, server_name: string|null, message: string}>
     * }  $summary
     */
    public static function formatImportSummary(array $summary): string
    {
        $parts = [
            sprintf('Imported/updated %d %s.', $summary['imported'], str('site')->plural($summary['imported'])),
            sprintf('%d %s imported.', $summary['imported_servers'], str('server')->plural($summary['imported_servers'])),
            sprintf('%d %s skipped.', $summary['skipped_servers'], str('server')->plural($summary['skipped_servers'])),
            sprintf('%d %s failed.', $summary['failed_servers'], str('server')->plural($summary['failed_servers'])),
        ];

        if ($summary['failed_servers'] > 0) {
            $failedServers = collect($summary['failures'])
                ->take(3)
                ->map(fn (array $failure): string => $failure['server_name'] ?: (string) $failure['server_id'])
                ->filter()
                ->join(', ');

            if ($failedServers !== '') {
                $parts[] = 'Failed servers: '.$failedServers.'.';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     *
     * @throws Exception
     */
    protected function fetchSitesForServer(PloiServers $server, string $token): Collection
    {
        $page = 1;
        $sites = collect();

        do {
            $response = $this->getSitesPage($server, $token, $page);

            if (! $response->ok()) {
                throw new Exception("Failed to fetch sites for server {$server->server_id}: ".$response->body());
            }

            $json = $response->json();
            $sites = $sites->merge($json['data'] ?? []);

            $page++;
        } while (isset($json['meta']['last_page']) && $page <= $json['meta']['last_page']);

        return $sites;
    }

    protected function getSitesPage(PloiServers $server, string $token, int $page): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->get("https://ploi.io/api/servers/{$server->server_id}/sites", [
            'per_page' => 50,
            'page' => $page,
        ]);
    }
}
