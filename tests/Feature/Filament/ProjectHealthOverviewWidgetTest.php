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

        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'current_status' => 'healthy',
            'stale_at' => null,
        ]);
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'current_status' => 'warning',
            'stale_at' => now()->subMinutes(10),
        ]);

        MonitorApis::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'current_status' => 'danger',
            'stale_at' => null,
        ]);
        MonitorApis::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'current_status' => null,
            'stale_at' => null,
        ]);

        Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project])
            ->assertSuccessful()
            ->assertSee('Failing')
            ->assertSee('Healthy')
            ->assertSee('Stale / No data');
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

        $widget = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project]);

        $instance = $widget->instance();
        $reflection = new ReflectionMethod($instance, 'collectCounts');
        $reflection->setAccessible(true);
        $counts = $reflection->invoke($instance);

        expect($counts['tracked'])->toBe(1)
            ->and($counts['healthy'])->toBe(1)
            ->and($counts['failing'])->toBe(0)
            ->and($counts['stale'])->toBe(0);
    });

    it('treats websites whose stale_at has elapsed as stale even when current_status is healthy', function () {
        Website::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'source' => 'package',
            'current_status' => 'healthy',
            'stale_at' => now()->subMinutes(10),
        ]);

        $widget = Livewire::test(ProjectHealthOverviewWidget::class, ['record' => $this->project]);

        $reflection = new ReflectionMethod($widget->instance(), 'collectCounts');
        $reflection->setAccessible(true);
        $counts = $reflection->invoke($widget->instance());

        expect($counts['tracked'])->toBe(1)
            ->and($counts['stale'])->toBe(1)
            ->and($counts['healthy'])->toBe(0);
    });
});
