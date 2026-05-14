<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PROJECT_KEY = 'vpn-proxy-pool';

    private const MONITOR_PACKAGE_NAME = 'vpn-proxy-pool-service-health';

    private const MONITOR_TITLE = 'VPN Proxy Pool Service Health';

    private const STATUS_URL = 'https://vpn-proxies.cococococ.com/api/v1/rest/status?token=afifi';

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('projects') || ! Schema::hasTable('monitor_apis')) {
            return;
        }

        $userId = DB::table('users')->orderBy('id')->value('id');

        if ($userId === null) {
            return;
        }

        $now = now();
        $projectId = $this->projectId((int) $userId, $now);
        $monitorId = $this->monitorId((int) $userId, $projectId, $now);

        if (! Schema::hasTable('monitor_api_assertions')) {
            return;
        }

        DB::table('monitor_api_assertions')
            ->where('monitor_api_id', $monitorId)
            ->delete();

        DB::table('monitor_api_assertions')->insert([
            $this->assertion($monitorId, 'data.proxies.healthy', '>', '0', 1, $now),
            $this->assertion($monitorId, 'data.nodes.active', '>', '0', 2, $now),
            $this->assertion($monitorId, 'data.proxies.unhealthy', '=', '0', 3, $now),
            $this->assertion($monitorId, 'data.proxies.quarantined', '=', '0', 4, $now),
            $this->assertion($monitorId, 'data.proxies.slow', '=', '0', 5, $now),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitor_apis')) {
            return;
        }

        $monitorId = DB::table('monitor_apis')
            ->where('package_name', self::MONITOR_PACKAGE_NAME)
            ->value('id');

        if ($monitorId !== null && Schema::hasTable('monitor_api_assertions')) {
            DB::table('monitor_api_assertions')
                ->where('monitor_api_id', $monitorId)
                ->delete();
        }

        DB::table('monitor_apis')
            ->where('package_name', self::MONITOR_PACKAGE_NAME)
            ->delete();

        if (! Schema::hasTable('projects')) {
            return;
        }

        $project = DB::table('projects')
            ->where('package_key', self::PROJECT_KEY)
            ->first();

        if ($project === null) {
            return;
        }

        $hasRelatedMonitors = Schema::hasColumn('monitor_apis', 'project_id')
            && DB::table('monitor_apis')->where('project_id', $project->id)->exists();

        $hasRelatedWebsites = Schema::hasTable('websites')
            && Schema::hasColumn('websites', 'project_id')
            && DB::table('websites')->where('project_id', $project->id)->exists();

        $hasRelatedComponents = Schema::hasTable('project_components')
            && Schema::hasColumn('project_components', 'project_id')
            && DB::table('project_components')->where('project_id', $project->id)->exists();

        if (! $hasRelatedMonitors && ! $hasRelatedWebsites && ! $hasRelatedComponents) {
            DB::table('projects')->where('id', $project->id)->delete();
        }
    }

    private function projectId(int $userId, \DateTimeInterface $now): ?int
    {
        $project = DB::table('projects')
            ->where('created_by', $userId)
            ->where('environment', 'production')
            ->where('package_key', self::PROJECT_KEY)
            ->first();

        $attributes = [
            'name' => 'VPN Proxy Pool',
            'package_key' => self::PROJECT_KEY,
            'group' => 'Infrastructure',
            'environment' => 'production',
            'technology' => 'REST API',
            'base_url' => 'https://vpn-proxies.cococococ.com',
            'created_by' => $userId,
            'updated_at' => $now,
        ];

        if ($project !== null) {
            DB::table('projects')->where('id', $project->id)->update($attributes);

            return (int) $project->id;
        }

        return (int) DB::table('projects')->insertGetId($attributes + [
            'token' => Str::random(48),
            'created_at' => $now,
        ]);
    }

    private function monitorId(int $userId, ?int $projectId, \DateTimeInterface $now): int
    {
        $monitor = DB::table('monitor_apis')
            ->where('package_name', self::MONITOR_PACKAGE_NAME)
            ->first()
            ?? DB::table('monitor_apis')->where('url', self::STATUS_URL)->first();

        $attributes = [
            'project_id' => $projectId,
            'title' => self::MONITOR_TITLE,
            'url' => self::STATUS_URL,
            'http_method' => 'GET',
            'request_path' => '/api/v1/rest/status',
            'data_path' => 'data',
            'expected_status' => 200,
            'timeout_seconds' => 10,
            'package_schedule' => '5m',
            'is_enabled' => true,
            'project_paused_monitoring' => false,
            'save_failed_response' => true,
            'created_by' => $userId,
            'source' => 'manual',
            'package_name' => self::MONITOR_PACKAGE_NAME,
            'package_interval' => '5m',
            'current_status' => null,
            'last_heartbeat_at' => null,
            'awaiting_heartbeat_since' => null,
            'stale_at' => null,
            'status_summary' => null,
            'diagnostic_queued_at' => null,
            'updated_at' => $now,
        ];

        if ($monitor !== null) {
            DB::table('monitor_apis')->where('id', $monitor->id)->update($attributes + [
                'deleted_at' => null,
            ]);

            return (int) $monitor->id;
        }

        return (int) DB::table('monitor_apis')->insertGetId($attributes + [
            'headers' => null,
            'created_at' => $now,
        ]);
    }

    private function assertion(int $monitorId, string $path, string $operator, string $value, int $sortOrder, \DateTimeInterface $now): array
    {
        return [
            'monitor_api_id' => $monitorId,
            'data_path' => $path,
            'assertion_type' => 'value_compare',
            'expected_type' => null,
            'comparison_operator' => $operator,
            'expected_value' => $value,
            'regex_pattern' => null,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
