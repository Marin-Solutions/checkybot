<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProxyPoolIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ProxyPoolDashboardService
{
    public const COMPONENT_SOURCE = 'proxy_pool';

    public function __construct(
        protected ProjectComponentNotificationService $projectComponentNotificationService
    ) {}

    public function syncIntegration(ProxyPoolIntegration $integration): ProjectComponent
    {
        $project = $integration->project()->firstOrFail();
        $name = trim($integration->name);
        $interval = trim($integration->check_interval) ?: '5m';
        $observedAt = now();

        if (! IntervalParser::isValid($interval)) {
            throw new RuntimeException("Proxy pool [{$name}] has an invalid interval [{$interval}].");
        }

        try {
            $dashboard = $this->fetchDashboard([
                'base_url' => $integration->base_url,
                'token' => $integration->token,
            ]);

            $component = $this->recordDashboardHeartbeat(
                integration: $integration,
                project: $project,
                name: $name,
                interval: $interval,
                dashboard: $dashboard,
                observedAt: $observedAt,
            );

            $integration->forceFill([
                'last_sync_status' => $component->current_status,
                'last_sync_error' => null,
                'last_synced_at' => $observedAt,
            ])->save();

            return $component;
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            $component = $this->recordFailureHeartbeat(
                integration: $integration,
                project: $project,
                name: $name,
                interval: $interval,
                observedAt: $observedAt,
                message: $exception->getMessage(),
            );

            $integration->forceFill([
                'last_sync_status' => 'danger',
                'last_sync_error' => $exception->getMessage(),
                'last_synced_at' => $observedAt,
            ])->save();

            return $component;
        }
    }

    /**
     * @param  array{name?: string, base_url?: string|null, token?: string|null}  $pool
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function fetchDashboard(array $pool): array
    {
        $baseUrl = trim((string) ($pool['base_url'] ?? ''));
        $token = trim((string) ($pool['token'] ?? ''));

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Proxy pool base URL and REST token are required.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(2, 250)
            ->get($this->dashboardUrl($baseUrl), [
                'token' => $token,
            ])
            ->throw();

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new RuntimeException('Proxy pool dashboard response did not include a data object.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function recordDashboardHeartbeat(
        ProxyPoolIntegration $integration,
        Project $project,
        string $name,
        string $interval,
        array $dashboard,
        Carbon $observedAt,
    ): ProjectComponent {
        $metrics = $this->dashboardMetrics($dashboard);
        $status = $this->statusForMetrics($metrics);
        $summary = $this->summaryForMetrics($metrics);

        return $this->recordHeartbeat(
            integration: $integration,
            project: $project,
            name: $name,
            interval: $interval,
            status: $status,
            summary: $summary,
            metrics: $metrics,
            observedAt: $observedAt,
        );
    }

    private function recordFailureHeartbeat(
        ProxyPoolIntegration $integration,
        Project $project,
        string $name,
        string $interval,
        Carbon $observedAt,
        string $message,
    ): ProjectComponent {
        return $this->recordHeartbeat(
            integration: $integration,
            project: $project,
            name: $name,
            interval: $interval,
            status: 'danger',
            summary: 'Proxy pool API check failed: '.$message,
            metrics: [
                'attention_total' => 1,
                'api_error' => $message,
            ],
            observedAt: $observedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function recordHeartbeat(
        ProxyPoolIntegration $integration,
        Project $project,
        string $name,
        string $interval,
        string $status,
        string $summary,
        array $metrics,
        Carbon $observedAt,
    ): ProjectComponent {
        return DB::transaction(function () use ($integration, $project, $name, $interval, $status, $summary, $metrics): ProjectComponent {
            $componentName = $this->componentName($name);

            $component = $integration->project_component_id !== null
                ? ProjectComponent::query()
                    ->whereKey($integration->project_component_id)
                    ->where('created_by', $project->created_by)
                    ->first()
                : null;

            $component ??= ProjectComponent::query()->firstOrNew([
                'project_id' => $project->getKey(),
                'name' => $componentName,
            ]);

            $previousStatus = $component->exists ? $component->current_status : 'unknown';

            $component->fill([
                'project_id' => $project->getKey(),
                'name' => $componentName,
                'source' => self::COMPONENT_SOURCE,
                'declared_interval' => $interval,
                'interval_minutes' => IntervalParser::toMinutes($interval),
                'current_status' => $status,
                'last_reported_status' => $status,
                'summary' => $summary,
                'metrics' => $metrics,
                'is_archived' => false,
                'archived_at' => null,
                'archive_reason' => null,
                'created_by' => $project->created_by,
            ]);
            $component->save();

            if ((int) $integration->project_component_id !== (int) $component->getKey()) {
                $integration->forceFill(['project_component_id' => $component->getKey()])->save();
            }

            if (in_array($status, ['warning', 'danger'], true) && $previousStatus !== $status) {
                $this->projectComponentNotificationService->notify($component->loadMissing('project'), 'heartbeat', $status);
            } elseif ($status === 'healthy' && in_array($previousStatus, ['warning', 'danger'], true)) {
                $this->projectComponentNotificationService->notify($component->loadMissing('project'), 'recovered', $status);
            }

            return $component;
        });
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function dashboardMetrics(array $dashboard): array
    {
        $accountsExpiringSoon = $this->integerMetric($dashboard, 'accounts_expiring_soon');
        $unhealthyProxies = $this->integerMetric($dashboard, 'unhealthy_proxies');
        $slowProxies = $this->integerMetric($dashboard, 'slow_proxies');
        $healthyProxies = $this->integerMetric($dashboard, 'healthy_proxies');

        return [
            'attention_total' => $accountsExpiringSoon + $unhealthyProxies + $slowProxies,
            'accounts_expiring_soon' => $accountsExpiringSoon,
            'healthy_proxies' => $healthyProxies,
            'unhealthy_proxies' => $unhealthyProxies,
            'slow_proxies' => $slowProxies,
            'thresholds' => Arr::get($dashboard, 'thresholds', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function statusForMetrics(array $metrics): string
    {
        if (($metrics['unhealthy_proxies'] ?? 0) > 0) {
            return 'danger';
        }

        if (($metrics['accounts_expiring_soon'] ?? 0) > 0 || ($metrics['slow_proxies'] ?? 0) > 0) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function summaryForMetrics(array $metrics): string
    {
        return sprintf(
            '%d accounts expiring soon, %d unhealthy proxies, %d slow proxies, %d healthy proxies.',
            $metrics['accounts_expiring_soon'] ?? 0,
            $metrics['unhealthy_proxies'] ?? 0,
            $metrics['slow_proxies'] ?? 0,
            $metrics['healthy_proxies'] ?? 0,
        );
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function integerMetric(array $dashboard, string $key): int
    {
        return max(0, (int) ($dashboard[$key] ?? 0));
    }

    private function dashboardUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/api/v1/rest/dashboard';
    }

    private function componentName(string $name): string
    {
        $name = Str::of($name)->trim()->squish()->value();

        return 'Proxy Pool: '.($name === '' ? 'Default' : $name);
    }
}
