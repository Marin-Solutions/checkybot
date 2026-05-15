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

    it('groups failing, healthy and pending counts across components, websites and APIs', function () {
        // 1 healthy component, 1 failing component, 1 pending component
        $healthyComponent = ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
            'is_stale' => false,
        ]);
        MonitorApis::factory()->create([
            'project_id' => null,
            'project_component_id' => $healthyComponent->id,
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'healthy',
        ]);

        $dangerComponent = ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'danger',
            'is_stale' => false,
        ]);
        MonitorApis::factory()->create([
            'project_id' => null,
            'project_component_id' => $dangerComponent->id,
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'danger',
        ]);

        $warningComponent = ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'warning',
            'is_stale' => true,
        ]);
        MonitorApis::factory()->create([
            'project_id' => null,
            'project_component_id' => $warningComponent->id,
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'warning',
        ]);

        // 1 healthy website, 1 warning website
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

        // 1 failing API, 1 pending API
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
            ->assertSee('Pending')
            ->assertDontSee('Stale / No data');

        $counts = $widget->instance()->collectCounts();

        expect($counts)->toMatchArray([
            'tracked' => 7,
            'failing' => 4,
            'healthy' => 2,
            'pending' => 1,
            'failing_components' => 2,
            'failing_websites' => 1,
            'failing_apis' => 1,
        ]);
    });

    it('ignores archived components and other projects', function () {
        $component = ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'healthy',
        ]);
        MonitorApis::factory()->create([
            'project_id' => null,
            'project_component_id' => $component->id,
            'created_by' => $this->user->id,
            'is_enabled' => true,
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
            ->and($counts['pending'])->toBe(0);
    });

    it('counts explicit component warning or danger as failing before awaiting heartbeat state', function () {
        $component = ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'current_status' => 'danger',
            'last_heartbeat_at' => null,
            'is_stale' => false,
        ]);
        MonitorApis::factory()->create([
            'project_id' => null,
            'project_component_id' => $component->id,
            'created_by' => $this->user->id,
            'is_enabled' => true,
            'current_status' => 'danger',
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['failing'])->toBe(1)
            ->and($counts['failing_components'])->toBe(1)
            ->and($counts['pending'])->toBe(0);
    });

    it('ignores legacy stale_at when counting website current status', function () {
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
            ->and($counts['healthy'])->toBe(1)
            ->and($counts['pending'])->toBe(0);
    });

    it('does not use package freshness thresholds for project health counts', function () {
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
            ->and($counts['healthy'])->toBe(1)
            ->and($counts['pending'])->toBe(0);
    });

    it('ignores legacy stale_at when counting api current status', function () {
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
            ->and($counts['failing'])->toBe(1)
            ->and($counts['pending'])->toBe(0);
    });

    it('excludes paused websites from the failing counts', function () {
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
            'ssl_check' => false,
            'current_status' => 'danger',
            'stale_at' => now()->subHour(),
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['failing'])->toBe(0)
            ->and($counts['pending'])->toBe(0)
            ->and($counts['healthy'])->toBe(1);
    });

    it('includes ssl-only websites in the project health counts', function () {
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'uptime_check' => false,
            'ssl_check' => true,
            'current_status' => 'warning',
            'stale_at' => null,
        ]);

        $counts = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->instance()
            ->collectCounts();

        expect($counts['tracked'])->toBe(1)
            ->and($counts['failing'])->toBe(1)
            ->and($counts['failing_websites'])->toBe(1);
    });

    it('excludes disabled API monitors from the failing counts', function () {
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
            ->and($counts['pending'])->toBe(0)
            ->and($counts['healthy'])->toBe(1);
    });
});
