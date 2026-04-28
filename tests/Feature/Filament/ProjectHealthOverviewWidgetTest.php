<?php

use App\Filament\Resources\Projects\Widgets\ProjectHealthOverviewWidget;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use Livewire\Livewire;

describe('ProjectHealthOverviewWidget', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
        $this->project = Project::factory()->create([
            'name' => 'Payments App',
            'created_by' => $this->user->id,
        ]);
    });

    it('shows an empty placeholder when the project tracks nothing', function () {
        Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->assertSuccessful()
            ->assertSee('Tracked surfaces')
            ->assertSee('No websites, APIs or components tracked yet');
    });

    it('groups failing, healthy and stale counts across components, websites and APIs', function () {
        // 1 healthy component, 1 failing component, 1 stale component
        ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
            'is_stale' => false,
        ]);
        ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'danger',
            'is_stale' => false,
        ]);
        ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'warning',
            'is_stale' => true,
        ]);

        // 1 healthy website, 1 stale website
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'uptime_check' => true,
            'current_status' => 'healthy',
            'stale_at' => null,
        ]);
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'package_interval' => '5m',
            'uptime_check' => true,
            'current_status' => 'warning',
            'last_heartbeat_at' => now()->subMinutes(15),
            'stale_at' => now()->subMinutes(10),
        ]);

        // 1 failing API, 1 awaiting first heartbeat (no data)
        MonitorApis::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'is_enabled' => true,
            'current_status' => 'danger',
            'stale_at' => null,
        ]);
        MonitorApis::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'is_enabled' => true,
            'current_status' => null,
            'stale_at' => null,
        ]);

        $widget = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->assertSuccessful()
            ->assertSee('Failing')
            ->assertSee('Healthy')
            ->assertSee('Stale / No data');

        $counts = $widget->instance()->collectCounts();

        expect($counts)->toMatchArray([
            'tracked' => 7,
            'failing' => 2, // 1 danger component + 1 danger api (warning component is stale, not failing)
            'healthy' => 2, // 1 healthy component + 1 healthy website
            'stale' => 2,   // 1 stale component + 1 stale website
            'no_data' => 1, // 1 api awaiting heartbeat
            'failing_components' => 1,
            'failing_websites' => 0,
            'failing_apis' => 1,
        ]);
    });

    it('ignores archived components and other projects', function () {
        ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);
        ProjectComponent::factory()->archived()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'danger',
        ]);

        $otherProject = Project::factory()->create(['created_by' => $this->user->id]);
        ProjectComponent::factory()->create([
            'project_id' => $otherProject->id,
            'created_by' => $this->user->id,
            'current_status' => 'danger',
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['healthy'])->toBe(1)
            ->and($counts['failing'])->toBe(0)
            ->and($counts['stale'])->toBe(0);
    });

    it('treats package websites with detected stale_at as stale even when current_status is healthy', function () {
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'package_interval' => '5m',
            'uptime_check' => true,
            'current_status' => 'healthy',
            'stale_at' => now()->addMinutes(10),
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['stale'])->toBe(1)
            ->and($counts['healthy'])->toBe(0);
    });

    it('uses package freshness thresholds for websites before stale_at is written', function () {
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'package_interval' => '5m',
            'uptime_check' => true,
            'current_status' => 'healthy',
            'last_heartbeat_at' => now()->subMinutes(6),
            'stale_at' => null,
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['stale'])->toBe(1)
            ->and($counts['healthy'])->toBe(0);
    });

    it('treats package apis with detected stale_at as stale even when the detection time is not in the past', function () {
        MonitorApis::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'package_interval' => '5m',
            'is_enabled' => true,
            'current_status' => 'danger',
            'last_heartbeat_at' => now()->subMinutes(2),
            'stale_at' => now()->addMinutes(10),
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['stale'])->toBe(1)
            ->and($counts['failing'])->toBe(0);
    });

    it('excludes paused websites from the failing and stale counts', function () {
        // An actively-monitored healthy website
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'uptime_check' => true,
            'current_status' => 'healthy',
        ]);

        // A paused website that previously went into danger — should not count
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'uptime_check' => false,
            'current_status' => 'danger',
            'stale_at' => now()->subHour(),
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['failing'])->toBe(0)
            ->and($counts['stale'])->toBe(0)
            ->and($counts['healthy'])->toBe(1);
    });

    it('excludes disabled API monitors from the failing and stale counts', function () {
        MonitorApis::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'is_enabled' => true,
            'current_status' => 'healthy',
        ]);

        // A disabled API that was failing — should not count
        MonitorApis::factory()->disabled()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'current_status' => 'danger',
            'stale_at' => now()->subHour(),
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['failing'])->toBe(0)
            ->and($counts['stale'])->toBe(0)
            ->and($counts['healthy'])->toBe(1);
    });
});
