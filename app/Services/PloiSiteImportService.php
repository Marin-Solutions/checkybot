<?php

namespace App\Services;

use App\Models\PloiAccounts;
use App\Models\PloiServers;
use App\Models\PloiWebsites;
use Exception;
use Illuminate\Database\Eloquent\Model;
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
     *
     * @throws Exception
     */
    public function import(?PloiServers $server = null): int
    {
        $imported = 0;
        $token = $this->account->key;
        $servers = $server ? collect([$server]) : PloiServers::where('ploi_account_id', $this->account->id)->get();

        foreach ($servers as $srv) {
            $page = 1;
            do {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->get("https://ploi.io/api/servers/{$srv->server_id}/sites", [
                    'per_page' => 50,
                    'page' => $page,
                ]);

                if (! $response->ok()) {
                    throw new \Exception("Failed to fetch sites for server {$srv->server_id}: ".$response->body());
                }

                $json = $response->json();
                $sites = $json['data'] ?? [];

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

                $page++;
            } while (isset($json['meta']['last_page']) && $page <= $json['meta']['last_page']);
        }

        return $imported;
    }
}
