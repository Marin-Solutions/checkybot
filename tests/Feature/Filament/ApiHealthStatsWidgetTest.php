<?php

use App\Filament\Widgets\ApiHealthStatsWidget;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\User;
use Livewire\Livewire;

describe('ApiHealthStatsWidget', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
    });

    it('renders an empty state when the user has no API monitors', function () {
        Livewire::test(ApiHealthStatsWidget::class)
            ->assertSuccessful()
            ->assertSee('API Monitors')
            ->assertSee('No APIs configured');
    });

    it('separates enabled disabled pending healthy and failing monitors', function () {
        $healthy = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'healthy',
            'stale_at' => null,
        ]);
        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $healthy->id,
        ]);

        $warning = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'warning',
            'stale_at' => null,
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $warning->id,
            'status' => 'warning',
        ]);

        $danger = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'danger',
            'stale_at' => null,
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $danger->id,
        ]);

        MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'unknown',
            'stale_at' => null,
        ]);

        $legacyStale = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'danger',
            'stale_at' => now()->subMinutes(10),
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $legacyStale->id,
        ]);

        $disabled = MonitorApis::factory()->disabled()->create([
            'created_by' => $this->user->id,
            'current_status' => 'danger',
            'stale_at' => now()->subMinutes(10),
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $disabled->id,
        ]);

        $counts = Livewire::test(ApiHealthStatsWidget::class)
            ->assertSuccessful()
            ->instance()
            ->collectCounts();

        expect($counts)->toMatchArray([
            'total' => 6,
            'enabled' => 5,
            'disabled' => 1,
            'pending' => 1,
            'healthy' => 1,
            'failing' => 3,
        ]);
    });

    it('treats enabled monitors without scheduled results as no data even when status looks healthy', function () {
        MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'healthy',
            'stale_at' => null,
        ]);

        $counts = Livewire::test(ApiHealthStatsWidget::class)
            ->instance()
            ->collectCounts();

        expect($counts['healthy'])->toBe(0)
            ->and($counts['pending'])->toBe(1)
            ->and($counts['failing'])->toBe(0);
    });

    it('ignores on-demand diagnostics when classifying live dashboard health', function () {
        $monitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'healthy',
            'stale_at' => null,
        ]);

        MonitorApiResult::factory()->successful()->onDemand()->create([
            'monitor_api_id' => $monitor->id,
        ]);

        $counts = Livewire::test(ApiHealthStatsWidget::class)
            ->instance()
            ->collectCounts();

        expect($counts['healthy'])->toBe(0)
            ->and($counts['pending'])->toBe(1);
    });

    it('scopes counts to the authenticated user', function () {
        $otherUser = User::factory()->create();

        $otherMonitor = MonitorApis::factory()->create([
            'created_by' => $otherUser->id,
            'current_status' => 'danger',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $otherMonitor->id,
        ]);

        $monitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);
        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $monitor->id,
        ]);

        $counts = Livewire::test(ApiHealthStatsWidget::class)
            ->instance()
            ->collectCounts();

        expect($counts)->toMatchArray([
            'total' => 1,
            'healthy' => 1,
            'failing' => 0,
        ]);
    });

    it('excludes soft-deleted monitors from live uptime and latency aggregates', function () {
        $activeMonitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);
        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $activeMonitor->id,
            'response_time_ms' => 120,
            'created_at' => now()->subMinutes(10),
        ]);

        $deletedMonitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'danger',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $deletedMonitor->id,
            'response_time_ms' => 900,
            'created_at' => now()->subMinutes(5),
        ]);
        $deletedMonitor->delete();

        Livewire::test(ApiHealthStatsWidget::class)
            ->assertSuccessful()
            ->assertSee('100%')
            ->assertSee('Avg response: 120ms');
    });

    it('ignores scheduled results for soft-deleted monitors when checking no-data state', function () {
        $activeMonitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);

        $deletedMonitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'danger',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $deletedMonitor->id,
        ]);
        $deletedMonitor->delete();

        $counts = Livewire::test(ApiHealthStatsWidget::class)
            ->instance()
            ->collectCounts();

        expect($counts)->toMatchArray([
            'total' => 1,
            'healthy' => 0,
            'failing' => 0,
            'pending' => 1,
        ]);
    });

    it('renders separated dashboard descriptions', function () {
        $healthy = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);
        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $healthy->id,
        ]);

        MonitorApis::factory()->disabled()->create([
            'created_by' => $this->user->id,
            'current_status' => 'danger',
        ]);

        Livewire::test(ApiHealthStatsWidget::class)
            ->assertSuccessful()
            ->assertSee('1 enabled, 1 disabled')
            ->assertSee('0 warning/failing, 0 pending')
            ->assertSee('Pending');
    });

    it('renders on the dashboard page for a super admin', function () {
        $monitor = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);
        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $monitor->id,
        ]);

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSeeLivewire(ApiHealthStatsWidget::class);
    });
});
