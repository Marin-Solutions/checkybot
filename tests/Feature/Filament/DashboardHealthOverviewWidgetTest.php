<?php

use App\Filament\Pages\HealthOverview;
use App\Filament\Widgets\DashboardHealthOverviewWidget;
use App\Models\MonitorApis;
use App\Models\Website;
use Livewire\Livewire;

it('shows green warning and critical dashboard health buckets', function () {
    $user = $this->actingAsSuperAdmin();

    Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
    ]);

    Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(20)->toDateString(),
    ]);

    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'danger',
    ]);

    Livewire::test(DashboardHealthOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Green')
        ->assertSee('Warning')
        ->assertSee('Critical')
        ->assertSee('33.3% of monitored checks');
});

it('renders the health overview drilldown page with filtered items', function () {
    $user = $this->actingAsSuperAdmin();

    Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Healthy uptime',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
    ]);

    Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Expired SSL',
        'uptime_check' => false,
        'ssl_check' => true,
        'ssl_expiry_date' => now()->subDay()->toDateString(),
    ]);

    $this->get(HealthOverview::getUrl(['status' => 'critical']))
        ->assertSuccessful()
        ->assertSee('Expired SSL')
        ->assertSee('SSL certificate')
        ->assertDontSee('Healthy uptime');
});
