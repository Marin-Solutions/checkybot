<?php

use App\Filament\Resources\Projects\Widgets\ProjectIncidentFeedWidget;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Livewire\Livewire;

describe('ProjectIncidentFeedWidget', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
        $this->project = Project::factory()->create([
            'name' => 'Payments App',
            'created_by' => $this->user->id,
        ]);
        $this->otherProject = Project::factory()->create([
            'name' => 'Reporting App',
            'created_by' => $this->user->id,
        ]);
    });

    it('renders successfully and shows the project-scoped empty state when the project has no incidents', function () {
        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSuccessful()
            ->assertSee('All clear')
            ->assertSee('this application');
    });

    it('shows incidents from this project and hides incidents from other projects', function () {
        $mineWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Mine homepage',
        ]);
        WebsiteLogHistory::factory()->create([
            'website_id' => $mineWebsite->id,
            'status' => 'danger',
            'summary' => 'Mine project incident',
            'created_at' => now()->subMinute(),
        ]);

        $theirsWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->otherProject->id,
            'name' => 'Other project homepage',
        ]);
        WebsiteLogHistory::factory()->create([
            'website_id' => $theirsWebsite->id,
            'status' => 'danger',
            'summary' => 'Other project incident',
            'created_at' => now()->subMinute(),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('Mine homepage')
            ->assertSee('Mine project incident')
            ->assertDontSee('Other project homepage')
            ->assertDontSee('Other project incident');
    });

    it('includes project-scoped API monitor failures and hides others', function () {
        $myApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'title' => 'Project checkout API',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $myApi->id,
            'summary' => 'Project checkout API broke',
            'created_at' => now()->subMinutes(2),
        ]);

        $otherApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->otherProject->id,
            'title' => 'Other project API',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $otherApi->id,
            'summary' => 'Other project API broke',
            'created_at' => now()->subMinutes(2),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('Project checkout API')
            ->assertSee('Project checkout API broke')
            ->assertDontSee('Other project API')
            ->assertDontSee('Other project API broke');
    });

    it('includes project-scoped component heartbeat incidents and hides others', function () {
        $myComponent = ProjectComponent::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->user->id,
            'name' => 'queue-worker',
        ]);
        ProjectComponentHeartbeat::factory()->create([
            'project_component_id' => $myComponent->id,
            'component_name' => 'queue-worker',
            'status' => 'warning',
            'summary' => 'Queue depth growing',
            'observed_at' => now()->subMinutes(3),
        ]);

        $otherComponent = ProjectComponent::factory()->create([
            'project_id' => $this->otherProject->id,
            'created_by' => $this->user->id,
            'name' => 'reporting-cron',
        ]);
        ProjectComponentHeartbeat::factory()->create([
            'project_component_id' => $otherComponent->id,
            'component_name' => 'reporting-cron',
            'status' => 'danger',
            'summary' => 'Reporting cron unreachable',
            'observed_at' => now()->subMinute(),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('queue-worker')
            ->assertSee('Queue depth growing')
            ->assertDontSee('reporting-cron')
            ->assertDontSee('Reporting cron unreachable');
    });

    it('keeps the project scope after a Livewire refresh (sort/filter/poll)', function () {
        $mineWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Mine homepage',
        ]);
        WebsiteLogHistory::factory()->create([
            'website_id' => $mineWebsite->id,
            'status' => 'danger',
            'summary' => 'Mine project incident',
            'created_at' => now()->subMinute(),
        ]);

        $theirsWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->otherProject->id,
            'name' => 'Other project homepage',
        ]);
        WebsiteLogHistory::factory()->create([
            'website_id' => $theirsWebsite->id,
            'status' => 'danger',
            'summary' => 'Other project incident',
            'created_at' => now()->subMinute(),
        ]);

        // Simulate a Livewire roundtrip (e.g. a sort change) by calling a
        // public action on the widget after the initial render. The base
        // table widget exposes `sortTable`, which retriggers the query —
        // the scope must still be applied.
        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->call('sortTable', 'occurred_at')
            ->assertSee('Mine homepage')
            ->assertDontSee('Other project homepage');
    });
});
