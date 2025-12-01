<?php

namespace App\Filament\Widgets;

use App\Models\Server;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ServerHealthStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $userId = auth()->id();

        $servers = Server::query()
            ->where('created_by', $userId)
            ->withLatestHistory()
            ->get();

        $totalServers = $servers->count();
        $onlineServers = 0;
        $offlineServers = 0;
        $warningServers = 0;
        $criticalServers = 0;

        foreach ($servers as $server) {
            $latestUpdate = $server->latest_server_history_created_at;
            $isOnline = $latestUpdate && Carbon::parse($latestUpdate)->diffInMinutes(now()) <= 2;

            if ($isOnline) {
                $onlineServers++;

                $info = $server->parseLatestServerHistoryInfo($server->latest_server_history_info);

                $cpuUsage = isset($info['cpu_usage']) ? (float) str_replace(',', '.', $info['cpu_usage']) : 0;
                $ramFree = isset($info['ram_usage']) ? (float) str_replace(['%', ' '], '', $info['ram_usage']) : 100;
                $diskFree = isset($info['disk_usage']) ? (float) str_replace(['%', ' '], '', $info['disk_usage']) : 100;

                $ramUsage = 100 - $ramFree;
                $diskUsage = 100 - $diskFree;

                if ($cpuUsage >= 90 || $ramUsage >= 90 || $diskUsage >= 90) {
                    $criticalServers++;
                } elseif ($cpuUsage >= 75 || $ramUsage >= 75 || $diskUsage >= 75) {
                    $warningServers++;
                }
            } else {
                $offlineServers++;
            }
        }

        return [
            Stat::make('Total Servers', $totalServers)
                ->description($onlineServers.' online, '.$offlineServers.' offline')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('primary'),

            Stat::make('Online', $onlineServers)
                ->description('Servers reporting data')
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),

            Stat::make('Offline', $offlineServers)
                ->description('No recent data received')
                ->descriptionIcon('heroicon-m-signal-slash')
                ->color($offlineServers > 0 ? 'danger' : 'gray'),

            Stat::make('Health Status', $this->getHealthDescription($warningServers, $criticalServers))
                ->description($criticalServers.' critical, '.$warningServers.' warning')
                ->descriptionIcon('heroicon-m-heart')
                ->color($criticalServers > 0 ? 'danger' : ($warningServers > 0 ? 'warning' : 'success')),
        ];
    }

    protected function getHealthDescription(int $warning, int $critical): string
    {
        if ($critical > 0) {
            return 'Critical';
        }

        if ($warning > 0) {
            return 'Warning';
        }

        return 'Healthy';
    }
}
