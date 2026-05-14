<?php

namespace App\Console\Commands;

use App\Models\ProxyPoolIntegration;
use App\Services\ProxyPoolDashboardService;
use Illuminate\Console\Command;

class SyncProxyPoolDashboard extends Command
{
    protected $signature = 'proxy-pool:sync-dashboard {--id= : Sync only one proxy pool integration ID}';

    protected $description = 'Fetch configured proxy pool dashboard metrics and record them as project component heartbeats.';

    public function handle(ProxyPoolDashboardService $proxyPoolDashboardService): int
    {
        $query = ProxyPoolIntegration::query()
            ->where('is_active', true)
            ->orderBy('id');

        $requestedId = $this->option('id');

        if (is_string($requestedId) && $requestedId !== '') {
            $query->whereKey((int) $requestedId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->components->info('No active proxy pool integrations are configured.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            $component = $proxyPoolDashboardService->syncIntegration($integration);

            $this->components->info(sprintf(
                '[%d] %s is %s: %s',
                $integration->getKey(),
                $component->name,
                $component->current_status,
                $component->summary,
            ));
        }

        return self::SUCCESS;
    }
}
