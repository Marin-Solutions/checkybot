<?php

namespace App\Services;

use App\Filament\Resources\MonitorApisResource;
use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use App\Filament\Resources\ServerResource;
use App\Filament\Resources\WebsiteResource;
use App\Models\MonitorApis;
use App\Models\ProjectComponent;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardHealthOverviewService
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_WARNING = 'warning';

    public const STATUS_CRITICAL = 'critical';

    /**
     * @return array<string, array{label: string, count: int, percent: float}>
     */
    public function summary(int $userId): array
    {
        $counts = [
            self::STATUS_HEALTHY => 0,
            self::STATUS_WARNING => 0,
            self::STATUS_CRITICAL => 0,
        ];

        $this->addLiveStatusSummary(
            $counts,
            Website::query()
                ->where('created_by', $userId)
                ->where('uptime_check', true)
        );

        $this->addSslSummary($counts, $userId);

        $this->addLiveStatusSummary(
            $counts,
            MonitorApis::query()
                ->where('created_by', $userId)
                ->where('is_enabled', true)
        );

        $this->addLiveStatusSummary(
            $counts,
            ProjectComponent::query()
                ->where('created_by', $userId)
                ->where('is_archived', false)
        );

        $this->addServerSummary($counts, $userId);

        $total = array_sum($counts);

        return [
            self::STATUS_HEALTHY => $this->summaryBucket('Green', $counts[self::STATUS_HEALTHY], $total),
            self::STATUS_WARNING => $this->summaryBucket('Warning', $counts[self::STATUS_WARNING], $total),
            self::STATUS_CRITICAL => $this->summaryBucket('Critical', $counts[self::STATUS_CRITICAL], $total),
        ];
    }

    /**
     * @return array<int, array{
     *     status: string,
     *     type: string,
     *     name: string,
     *     detail: string,
     *     url: string|null,
     * }>
     */
    public function items(int $userId, ?string $status = null): array
    {
        $items = [
            ...$this->uptimeItems($userId),
            ...$this->sslItems($userId),
            ...$this->apiItems($userId),
            ...$this->componentItems($userId),
            ...$this->serverItems($userId),
        ];

        if ($status !== null) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => $item['status'] === $status,
            ));
        }

        usort($items, fn (array $a, array $b): int => [
            self::STATUS_CRITICAL => 0,
            self::STATUS_WARNING => 1,
            self::STATUS_HEALTHY => 2,
        ][$a['status']] <=> [
            self::STATUS_CRITICAL => 0,
            self::STATUS_WARNING => 1,
            self::STATUS_HEALTHY => 2,
        ][$b['status']] ?: strcmp($a['type'].$a['name'], $b['type'].$b['name']));

        return $items;
    }

    /**
     * @return array{label: string, count: int, percent: float}
     */
    private function summaryBucket(string $label, int $count, int $total): array
    {
        return [
            'label' => $label,
            'count' => $count,
            'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  Builder<Website|MonitorApis|ProjectComponent>  $query
     */
    private function addLiveStatusSummary(array &$counts, Builder $query): void
    {
        $aggregate = $query
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN current_status = 'healthy' THEN 1 ELSE 0 END) as healthy_count")
            ->selectRaw("SUM(CASE WHEN current_status = 'danger' THEN 1 ELSE 0 END) as critical_count")
            ->first();

        $total = (int) ($aggregate?->total_count ?? 0);
        $healthy = (int) ($aggregate?->healthy_count ?? 0);
        $critical = (int) ($aggregate?->critical_count ?? 0);

        $counts[self::STATUS_HEALTHY] += $healthy;
        $counts[self::STATUS_CRITICAL] += $critical;
        $counts[self::STATUS_WARNING] += max(0, $total - $healthy - $critical);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function addSslSummary(array &$counts, int $userId): void
    {
        $in7Days = now()->addDays(7)->toDateString();
        $in30Days = now()->addDays(30)->toDateString();

        $aggregate = Website::query()
            ->where('created_by', $userId)
            ->where('ssl_check', true)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date IS NOT NULL AND ssl_expiry_date > ? THEN 1 ELSE 0 END) as healthy_count', [$in30Days])
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date IS NOT NULL AND ssl_expiry_date <= ? THEN 1 ELSE 0 END) as critical_count', [$in7Days])
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date IS NULL OR (ssl_expiry_date > ? AND ssl_expiry_date <= ?) THEN 1 ELSE 0 END) as warning_count', [$in7Days, $in30Days])
            ->first();

        $counts[self::STATUS_HEALTHY] += (int) ($aggregate?->healthy_count ?? 0);
        $counts[self::STATUS_WARNING] += (int) ($aggregate?->warning_count ?? 0);
        $counts[self::STATUS_CRITICAL] += (int) ($aggregate?->critical_count ?? 0);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function addServerSummary(array &$counts, int $userId): void
    {
        Server::query()
            ->where('created_by', $userId)
            ->withLatestHistory()
            ->get()
            ->each(function (Server $server) use (&$counts): void {
                [$status] = $this->serverBucket($server);

                $counts[$status]++;
            });
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function uptimeItems(int $userId): array
    {
        return Website::query()
            ->where('created_by', $userId)
            ->where('uptime_check', true)
            ->orderBy('name')
            ->get(['id', 'name', 'current_status', 'status_summary'])
            ->map(fn (Website $website): array => [
                'status' => $this->liveStatusBucket($website->current_status),
                'type' => 'Uptime check',
                'name' => $website->name,
                'detail' => $website->status_summary ?: $this->liveStatusLabel($website->current_status),
                'url' => WebsiteResource::getUrl('view', ['record' => $website]),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function sslItems(int $userId): array
    {
        return Website::query()
            ->where('created_by', $userId)
            ->where('ssl_check', true)
            ->orderBy('name')
            ->get(['id', 'name', 'ssl_expiry_date'])
            ->map(function (Website $website): array {
                [$status, $detail] = $this->sslBucket($website->ssl_expiry_date);

                return [
                    'status' => $status,
                    'type' => 'SSL certificate',
                    'name' => $website->name,
                    'detail' => $detail,
                    'url' => WebsiteResource::getUrl('view', ['record' => $website]),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function apiItems(int $userId): array
    {
        return MonitorApis::query()
            ->where('created_by', $userId)
            ->where('is_enabled', true)
            ->orderBy('title')
            ->get(['id', 'title', 'current_status', 'status_summary'])
            ->map(fn (MonitorApis $monitor): array => [
                'status' => $this->liveStatusBucket($monitor->current_status),
                'type' => 'API monitor',
                'name' => $monitor->title,
                'detail' => $monitor->status_summary ?: $this->liveStatusLabel($monitor->current_status),
                'url' => MonitorApisResource::getUrl('view', ['record' => $monitor]),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function componentItems(int $userId): array
    {
        return ProjectComponent::query()
            ->where('created_by', $userId)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'source', 'current_status', 'summary'])
            ->map(fn (ProjectComponent $component): array => [
                'status' => $this->liveStatusBucket($component->current_status),
                'type' => $component->source === ProxyPoolDashboardService::COMPONENT_SOURCE
                    ? 'Proxy pool'
                    : 'Application component',
                'name' => $component->name,
                'detail' => $component->summary ?: $this->liveStatusLabel($component->current_status),
                'url' => ProjectComponentResource::getUrl('view', ['record' => $component]),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function serverItems(int $userId): array
    {
        return Server::query()
            ->where('created_by', $userId)
            ->withLatestHistory()
            ->orderBy('servers.name')
            ->get()
            ->map(function (Server $server): array {
                [$status, $detail] = $this->serverBucket($server);

                return [
                    'status' => $status,
                    'type' => 'Server',
                    'name' => $server->name,
                    'detail' => $detail,
                    'url' => ServerResource::getUrl('view', ['record' => $server]),
                ];
            })
            ->all();
    }

    private function liveStatusBucket(?string $status): string
    {
        return match ($status) {
            'healthy' => self::STATUS_HEALTHY,
            'danger' => self::STATUS_CRITICAL,
            default => self::STATUS_WARNING,
        };
    }

    private function liveStatusLabel(?string $status): string
    {
        return match ($status) {
            'healthy' => 'Healthy',
            'warning' => 'Warning',
            'danger' => 'Critical',
            default => 'Awaiting a live result',
        };
    }

    /**
     * @return array{string, string}
     */
    private function sslBucket(?string $expiryDate): array
    {
        if ($expiryDate === null) {
            return [self::STATUS_WARNING, 'No SSL expiry date recorded'];
        }

        $expiry = Carbon::parse($expiryDate)->startOfDay();
        $days = now()->startOfDay()->diffInDays($expiry, false);

        if ($days < 0) {
            return [self::STATUS_CRITICAL, 'Expired '.abs($days).' days ago'];
        }

        if ($days <= 7) {
            return [self::STATUS_CRITICAL, "Expires in {$days} days"];
        }

        if ($days <= 30) {
            return [self::STATUS_WARNING, "Expires in {$days} days"];
        }

        return [self::STATUS_HEALTHY, "Expires in {$days} days"];
    }

    /**
     * @return array{string, string}
     */
    private function serverBucket(Server $server): array
    {
        if (! $server->hasFreshLatestHistory()) {
            return [self::STATUS_CRITICAL, 'No recent server data received'];
        }

        $info = $server->parseLatestServerHistoryInfo($server->latest_server_history_info);
        $cpuUsage = isset($info['cpu_usage']) ? $server->cpuLoadToUsagePercentage($info['cpu_usage']) : 0;
        $ramFree = isset($info['ram_usage']) ? (float) str_replace(['%', ' '], '', $info['ram_usage']) : 100;
        $diskFree = isset($info['disk_usage']) ? (float) str_replace(['%', ' '], '', $info['disk_usage']) : 100;
        $ramUsage = 100 - $ramFree;
        $diskUsage = 100 - $diskFree;

        $detail = sprintf('CPU %.0f%%, RAM %.0f%%, disk %.0f%%', $cpuUsage, $ramUsage, $diskUsage);

        if ($cpuUsage >= 90 || $ramUsage >= 90 || $diskUsage >= 90) {
            return [self::STATUS_CRITICAL, $detail];
        }

        if ($cpuUsage >= 75 || $ramUsage >= 75 || $diskUsage >= 75) {
            return [self::STATUS_WARNING, $detail];
        }

        return [self::STATUS_HEALTHY, $detail];
    }
}
