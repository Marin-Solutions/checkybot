<?php

use App\Filament\Resources\Projects\Widgets\ProjectIncidentFeedWidget;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
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

    it('shows only project-scoped transition rows and suppresses duplicate unhealthy runs', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Project transition homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'healthy',
            'summary' => 'Project baseline healthy',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'warning',
            'summary' => 'Project first warning',
            'created_at' => now()->subMinutes(4),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'warning',
            'summary' => 'Project duplicate warning',
            'created_at' => now()->subMinutes(3),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Project escalated to danger',
            'created_at' => now()->subMinutes(2),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('Project first warning')
            ->assertSee('Project escalated to danger')
            ->assertDontSee('Project duplicate warning');
    });

    it('shows project-scoped recovery transitions with resolved current state', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Recovered project homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Project homepage went down',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'healthy',
            'summary' => 'Project homepage recovered',
            'created_at' => now()->subMinutes(3),
        ]);

        $otherWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->otherProject->id,
            'name' => 'Recovered other homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $otherWebsite->id,
            'status' => 'danger',
            'summary' => 'Other homepage went down',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $otherWebsite->id,
            'status' => 'healthy',
            'summary' => 'Other homepage recovered',
            'created_at' => now()->subMinutes(3),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('Project homepage went down')
            ->assertSee('Project homepage recovered')
            ->assertSee('RECOVERED')
            ->assertSee('Resolved')
            ->assertDontSee('Other homepage went down')
            ->assertDontSee('Other homepage recovered');
    });

    it('filters project incidents by active and resolved current state', function () {
        $activeWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Active project homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $activeWebsite->id,
            'status' => 'danger',
            'summary' => 'Project checkout is still down',
            'created_at' => now()->subMinutes(6),
        ]);

        $resolvedWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Resolved project homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $resolvedWebsite->id,
            'status' => 'danger',
            'summary' => 'Project billing outage started',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $resolvedWebsite->id,
            'status' => 'healthy',
            'summary' => 'Project billing recovered',
            'created_at' => now()->subMinutes(4),
        ]);

        $otherProjectWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->otherProject->id,
            'name' => 'Other active homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $otherProjectWebsite->id,
            'status' => 'danger',
            'summary' => 'Other project is still down',
            'created_at' => now()->subMinutes(3),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('Project checkout is still down')
            ->assertSee('Project billing outage started')
            ->assertSee('Project billing recovered')
            ->assertDontSee('Other project is still down')
            ->filterTable('state', 'active')
            ->assertSee('Project checkout is still down')
            ->assertDontSee('Project billing outage started')
            ->assertDontSee('Project billing recovered')
            ->assertDontSee('Other project is still down');

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->filterTable('state', 'resolved')
            ->assertDontSee('Project checkout is still down')
            ->assertSee('Project billing outage started')
            ->assertSee('Project billing recovered')
            ->assertDontSee('Other project is still down');
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

    it('shows component context and keeps the component filter project scoped', function () {
        $checkoutComponent = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Checkout',
        ]);

        $billingComponent = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Billing',
        ]);

        $otherComponent = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->otherProject->id,
            'name' => 'Reporting',
        ]);

        $checkoutWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'project_component_id' => $checkoutComponent->id,
            'name' => 'Checkout homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $checkoutWebsite->id,
            'status' => 'danger',
            'summary' => 'Checkout homepage failing',
            'created_at' => now()->subMinutes(5),
        ]);

        $billingApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'project_component_id' => $billingComponent->id,
            'title' => 'Billing API',
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $billingApi->id,
            'summary' => 'Billing API failing',
            'created_at' => now()->subMinutes(4),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSee('Checkout')
            ->assertSee('Billing')
            ->assertDontSee('Reporting')
            ->filterTable('component_id', (string) $checkoutComponent->id)
            ->assertSee('Checkout homepage failing')
            ->assertDontSee('Billing API failing')
            ->assertDontSee('Reporting');

        expect($otherComponent->project_id)->toBe($this->otherProject->id);
    });

    it('excludes on-demand diagnostics from the project incident feed', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Project diagnostic homepage',
        ]);
        WebsiteLogHistory::factory()->onDemand()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Project Run Now returned HTTP 503',
            'created_at' => now()->subMinute(),
        ]);

        $api = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'title' => 'Project diagnostic API',
        ]);
        MonitorApiResult::factory()->failed()->onDemand()->create([
            'monitor_api_id' => $api->id,
            'summary' => 'Project Run Now API returned 500',
            'created_at' => now()->subMinute(),
        ]);

        Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertDontSee('Project diagnostic homepage')
            ->assertDontSee('Project Run Now returned HTTP 503')
            ->assertDontSee('Project diagnostic API')
            ->assertDontSee('Project Run Now API returned 500')
            ->assertSee('All clear');
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

    it('sanitizes unnamed evidence modal actions before resolving project feed actions', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Project cached modal homepage',
        ]);

        $log = WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Project cached modal homepage returned HTTP 503',
            'http_status_code' => 503,
            'created_at' => now()->subMinutes(5),
        ]);

        $incident = ProjectIncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7), $this->project->id)
            ->where('source', 'website')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->source_row_id)->toBe($log->id);

        $component = Livewire::test(ProjectIncidentFeedWidget::class, ['record' => $this->project])
            ->assertSuccessful();

        $cacheMountedActions = new ReflectionMethod(ProjectIncidentFeedWidget::class, 'cacheMountedActions');
        $resolvedActions = $cacheMountedActions->invoke($component->instance(), [
            [
                'name' => 'viewEvidence',
                'arguments' => [],
                'context' => [
                    'table' => true,
                    'recordKey' => $incident->getKey(),
                ],
                'data' => [],
            ],
            [
                'name' => '',
                'arguments' => [],
                'context' => [],
                'data' => [],
            ],
        ]);

        expect($resolvedActions)->toHaveCount(1);
        expect($component->instance()->mountedActions)
            ->toHaveCount(1)
            ->and($component->instance()->mountedActions[0]['name'])
            ->toBe('viewEvidence');
    });
});
