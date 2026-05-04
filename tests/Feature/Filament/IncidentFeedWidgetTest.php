<?php

use App\Filament\Widgets\IncidentFeedWidget;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Livewire\Livewire;

describe('IncidentFeedWidget', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
    });

    it('renders without errors', function () {
        Livewire::test(IncidentFeedWidget::class)
            ->assertSuccessful();
    });

    it('shows an empty state when there are no incidents', function () {
        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('All clear');
    });

    it('lists warning and danger website log history rows', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Acme Corp Homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Homepage returned HTTP 503',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'healthy',
            'summary' => 'All good',
            'created_at' => now()->subMinutes(1),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Acme Corp Homepage')
            ->assertSee('Homepage returned HTTP 503')
            ->assertDontSee('All good');
    });

    it('suppresses duplicate unhealthy rows until the severity changes', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Transition homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'healthy',
            'summary' => 'Baseline healthy',
            'created_at' => now()->subMinutes(6),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'warning',
            'summary' => 'First warning transition',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'warning',
            'summary' => 'Duplicate warning run',
            'created_at' => now()->subMinutes(4),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Escalated to danger',
            'created_at' => now()->subMinutes(3),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Duplicate danger run',
            'created_at' => now()->subMinutes(2),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('First warning transition')
            ->assertSee('Escalated to danger')
            ->assertDontSee('Duplicate warning run')
            ->assertDontSee('Duplicate danger run');
    });

    it('excludes on-demand website diagnostics from the incident feed', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Diagnostic homepage',
        ]);

        WebsiteLogHistory::factory()->onDemand()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Run Now returned HTTP 503',
            'created_at' => now()->subMinute(),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertDontSee('Diagnostic homepage')
            ->assertDontSee('Run Now returned HTTP 503')
            ->assertSee('All clear');
    });

    it('lists failing monitor API results even without an explicit status', function () {
        $api = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Billing webhook',
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'status' => null,
            'summary' => 'Billing webhook returned 500',
            'http_code' => 500,
            'created_at' => now()->subMinutes(3),
        ]);

        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $api->id,
            'created_at' => now()->subMinute(),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Billing webhook')
            ->assertSee('Billing webhook returned 500');
    });

    it('does not create a new api incident row for repeated failed runs without a status change', function () {
        $api = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Duplicate API failures',
        ]);

        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $api->id,
            'created_at' => now()->subMinutes(4),
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'status' => null,
            'summary' => 'First failed API transition',
            'http_code' => 500,
            'created_at' => now()->subMinutes(3),
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'status' => null,
            'summary' => 'Repeated failed API run',
            'http_code' => 500,
            'created_at' => now()->subMinutes(2),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('First failed API transition')
            ->assertDontSee('Repeated failed API run');
    });

    it('excludes on-demand API diagnostics from the incident feed', function () {
        $api = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Diagnostic API',
        ]);

        MonitorApiResult::factory()->failed()->onDemand()->create([
            'monitor_api_id' => $api->id,
            'summary' => 'Run Now API returned 500',
            'created_at' => now()->subMinute(),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertDontSee('Diagnostic API')
            ->assertDontSee('Run Now API returned 500')
            ->assertSee('All clear');
    });

    it('lists warning and danger component heartbeats', function () {
        $component = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'queue-worker',
        ]);

        ProjectComponentHeartbeat::factory()->create([
            'project_component_id' => $component->id,
            'component_name' => 'queue-worker',
            'status' => 'warning',
            'summary' => 'Latency spiking above threshold',
            'observed_at' => now()->subMinutes(10),
        ]);

        ProjectComponentHeartbeat::factory()->create([
            'project_component_id' => $component->id,
            'component_name' => 'queue-worker',
            'status' => 'healthy',
            'summary' => 'Back to normal',
            'observed_at' => now()->subMinute(),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('queue-worker')
            ->assertSee('Latency spiking above threshold')
            ->assertDontSee('Back to normal');
    });

    it('scopes incidents to the current user', function () {
        $otherUser = \App\Models\User::factory()->create();

        $mineWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Mine homepage',
        ]);

        $theirsWebsite = Website::factory()->create([
            'created_by' => $otherUser->id,
            'name' => 'Theirs homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $mineWebsite->id,
            'status' => 'danger',
            'summary' => 'Mine broke',
            'created_at' => now()->subMinute(),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $theirsWebsite->id,
            'status' => 'danger',
            'summary' => 'Theirs broke',
            'created_at' => now()->subMinute(),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Mine homepage')
            ->assertDontSee('Theirs homepage');
    });

    it('excludes incidents from soft-deleted websites and API monitors', function () {
        $deletedWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Deleted homepage',
        ]);
        WebsiteLogHistory::factory()->create([
            'website_id' => $deletedWebsite->id,
            'status' => 'danger',
            'summary' => 'Deleted homepage danger',
            'created_at' => now()->subMinute(),
        ]);
        $deletedWebsite->delete();

        $deletedApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Deleted API',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $deletedApi->id,
            'summary' => 'Deleted API danger',
            'created_at' => now()->subMinute(),
        ]);
        $deletedApi->delete();

        Livewire::test(IncidentFeedWidget::class)
            ->assertDontSee('Deleted homepage')
            ->assertDontSee('Deleted homepage danger')
            ->assertDontSee('Deleted API')
            ->assertDontSee('Deleted API danger')
            ->assertSee('All clear');
    });

    it('excludes incidents older than the 7-day window', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Ancient site',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Old ancient failure',
            'created_at' => now()->subDays(30),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertDontSee('Old ancient failure');
    });

    it('merges all three sources into one table', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Landing page',
        ]);
        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'warning',
            'summary' => 'Slow response',
            'created_at' => now()->subMinutes(15),
        ]);

        $api = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Checkout API',
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'summary' => 'Checkout failing',
            'created_at' => now()->subMinutes(10),
        ]);

        $component = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'redis-primary',
        ]);
        ProjectComponentHeartbeat::factory()->create([
            'project_component_id' => $component->id,
            'component_name' => 'redis-primary',
            'status' => 'danger',
            'summary' => 'Redis primary down',
            'observed_at' => now()->subMinutes(5),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Slow response')
            ->assertSee('Checkout failing')
            ->assertSee('Redis primary down');
    });
});
