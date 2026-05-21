<?php

use App\Filament\Widgets\IncidentFeedWidget;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\ProjectComponent;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('All good')
            ->assertSee('RECOVERED')
            ->assertSee('Resolved');
    });

    it('shows linked component context and filters incidents by component', function () {
        $checkoutComponent = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Checkout',
        ]);

        $searchComponent = ProjectComponent::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Search',
        ]);

        $checkoutApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $checkoutComponent->project_id,
            'project_component_id' => $checkoutComponent->id,
            'title' => 'Checkout API',
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $checkoutApi->id,
            'summary' => 'Checkout payments failing',
            'created_at' => now()->subMinutes(4),
        ]);

        $searchWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'project_id' => $searchComponent->project_id,
            'project_component_id' => $searchComponent->id,
            'name' => 'Search homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $searchWebsite->id,
            'status' => 'danger',
            'summary' => 'Search homepage failing',
            'created_at' => now()->subMinutes(3),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('subject', 'Checkout API')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->component_id)->toBe($checkoutComponent->id)
            ->and($incident->component_name)->toBe('Checkout');

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Checkout')
            ->assertSee('Search')
            ->filterTable('component_id', (string) $checkoutComponent->id)
            ->assertSee('Checkout')
            ->assertSee('Checkout payments failing')
            ->assertDontSee('Search homepage failing');
    });

    it('shows and filters website incidents by derived failure cause', function () {
        $dnsWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'DNS outage homepage',
        ]);

        WebsiteLogHistory::factory()->transportError('dns')->create([
            'website_id' => $dnsWebsite->id,
            'summary' => 'DNS lookup failed for homepage',
            'created_at' => now()->subMinutes(5),
        ]);

        $httpWebsite = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'HTTP outage homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $httpWebsite->id,
            'status' => 'danger',
            'summary' => 'Homepage returned HTTP 503',
            'http_status_code' => 503,
            'created_at' => now()->subMinutes(4),
        ]);

        $dnsIncident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('subject', 'DNS outage homepage')
            ->first();

        expect($dnsIncident)->not->toBeNull()
            ->and($dnsIncident->cause_key)->toBe('dns');

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('DNS')
            ->assertSee('HTTP')
            ->filterTable('cause_key', 'dns')
            ->assertSee('DNS lookup failed for homepage')
            ->assertDontSee('Homepage returned HTTP 503');
    });

    it('shows and filters API incidents by assertion and transport causes', function () {
        $assertionApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Assertion API',
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $assertionApi->id,
            'summary' => 'Expected active status.',
            'http_code' => 200,
            'failed_assertions' => [[
                'path' => 'data.status',
                'type' => 'value_compare',
                'message' => 'Expected active status.',
            ]],
            'created_at' => now()->subMinutes(5),
        ]);

        $timeoutApi = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Timeout API',
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $timeoutApi->id,
            'summary' => 'API request timed out',
            'http_code' => 0,
            'failed_assertions' => null,
            'transport_error_type' => 'timeout',
            'created_at' => now()->subMinutes(4),
        ]);

        $assertionIncident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('subject', 'Assertion API')
            ->first();

        $timeoutIncident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('subject', 'Timeout API')
            ->first();

        expect($assertionIncident)->not->toBeNull()
            ->and($assertionIncident->cause_key)->toBe('assertion')
            ->and($timeoutIncident)->not->toBeNull()
            ->and($timeoutIncident->cause_key)->toBe('timeout');

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Assertion')
            ->assertSee('Timeout')
            ->filterTable('cause_key', 'assertion')
            ->assertSee('Expected active status.')
            ->assertDontSee('API request timed out');
    });

    it('derives ssl cause for ssl-only expiry incidents', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Certificate expiry check',
            'uptime_check' => false,
            'ssl_check' => true,
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'warning',
            'summary' => 'Certificate expires soon',
            'http_status_code' => null,
            'ssl_expiry_date' => now()->addDays(3),
            'created_at' => now()->subMinutes(3),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('subject', 'Certificate expiry check')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->cause_key)->toBe('ssl');

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('SSL')
            ->filterTable('cause_key', 'ssl')
            ->assertSee('Certificate expires soon');
    });

    it('opens the exact website log evidence row from an incident', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Evidence homepage',
        ]);

        $log = WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Evidence homepage returned HTTP 503',
            'http_status_code' => 503,
            'speed' => 812,
            'created_at' => now()->subMinutes(5),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'website')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->source_row_id)->toBe($log->id);

        Livewire::test(IncidentFeedWidget::class)
            ->assertTableActionExists('viewEvidence', null, $incident->getKey())
            ->assertSee('View Evidence');

        $html = view('filament.widgets.incident-feed-evidence-modal', [
            'incident' => $incident,
            'evidence' => $log,
            'targetUrl' => null,
        ])->render();

        expect($html)
            ->toContain('Source row #'.$log->id)
            ->toContain('Evidence homepage returned HTTP 503')
            ->toContain('812ms');
    });

    it('mounts the evidence modal action without unnamed Livewire actions', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Livewire modal homepage',
        ]);

        $log = WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Livewire modal homepage returned HTTP 503',
            'http_status_code' => 503,
            'created_at' => now()->subMinutes(5),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'website')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->source_row_id)->toBe($log->id);

        $component = Livewire::test(IncidentFeedWidget::class)
            ->mountTableAction('viewEvidence', $incident->getKey())
            ->assertSuccessful()
            ->assertSet('mountedActions.0.name', 'viewEvidence')
            ->set('discoveredSchemaNames', ['table'])
            ->assertSuccessful()
            ->assertSet('mountedActions.0.name', 'viewEvidence');

        expect($component->getMountedActionModalHtml())
            ->toContain('Livewire modal homepage returned HTTP 503')
            ->toContain('Source row #'.$log->id)
            ->toContain('Close');

        expect($component->instance()->getMountedAction()->getModalCancelAction()->getName())
            ->toBe('cancel');
    });

    it('hydrates the evidence modal when Filament sends an unnamed nested action payload', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Hydration modal homepage',
        ]);

        $log = WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Hydration modal homepage returned HTTP 503',
            'http_status_code' => 503,
            'created_at' => now()->subMinutes(5),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'website')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->source_row_id)->toBe($log->id);

        $component = Livewire::test(IncidentFeedWidget::class)
            ->mountTableAction('viewEvidence', $incident->getKey())
            ->assertSuccessful();

        $component
            ->set('mountedActions', [
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
                    'name' => null,
                    'arguments' => [],
                    'context' => [],
                    'data' => [],
                ],
            ])
            ->set('discoveredSchemaNames', ['table'])
            ->assertSuccessful()
            ->assertSet('mountedActions.0.name', 'viewEvidence');

        expect($component->instance()->mountedActions)->toHaveCount(1);
        expect($component->getMountedActionModalHtml())
            ->toContain('Hydration modal homepage returned HTTP 503')
            ->toContain('Close');
    });

    it('hydrates the evidence modal when Livewire updates a nested mounted action name', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Nested modal homepage',
        ]);

        $log = WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Nested modal homepage returned HTTP 503',
            'http_status_code' => 503,
            'created_at' => now()->subMinutes(5),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'website')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->source_row_id)->toBe($log->id);

        $component = Livewire::test(IncidentFeedWidget::class)
            ->mountTableAction('viewEvidence', $incident->getKey())
            ->set('mountedActions', [
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
                    'name' => 'cancel',
                    'arguments' => [],
                    'context' => [],
                    'data' => [],
                ],
            ])
            ->set('mountedActions.1.name', null)
            ->assertSuccessful()
            ->assertSet('mountedActions.0.name', 'viewEvidence');

        expect($component->instance()->mountedActions)->toHaveCount(1);
        expect($component->getMountedActionModalHtml())
            ->toContain('Nested modal homepage returned HTTP 503')
            ->toContain('Close');
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

    it('shows active state for incident sources that have not recovered', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Still broken homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'healthy',
            'summary' => 'Baseline healthy',
            'created_at' => now()->subMinutes(5),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Current outage',
            'created_at' => now()->subMinutes(4),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Current outage')
            ->assertSee('Active')
            ->assertDontSee('Resolved');
    });

    it('resolves website incidents when website checks are disabled', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Suppressed homepage',
            'uptime_check' => false,
            'ssl_check' => false,
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Last scheduled website failure',
            'created_at' => now()->subMinutes(4),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'website')
            ->where('subject_id', $website->id)
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->state)->toBe('resolved');
    });

    it('resolves api incidents when the API monitor is disabled', function () {
        $api = MonitorApis::factory()->disabled()->create([
            'created_by' => $this->user->id,
            'title' => 'Suppressed API',
        ]);

        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'summary' => 'Last scheduled API failure',
            'created_at' => now()->subMinutes(4),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'api')
            ->where('subject_id', $api->id)
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->state)->toBe('resolved');
    });

    it('does not treat an already-open incident from before the feed window as a new incident', function () {
        $website = Website::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Long-running outage homepage',
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Outage started last week',
            'created_at' => now()->subDays(8),
        ]);

        WebsiteLogHistory::factory()->create([
            'website_id' => $website->id,
            'status' => 'danger',
            'summary' => 'Outage still active today',
            'created_at' => now()->subMinutes(5),
        ]);

        Livewire::test(IncidentFeedWidget::class)
            ->assertDontSee('Outage started last week')
            ->assertDontSee('Outage still active today')
            ->assertSee('All clear');
    });

    it('keeps separate prior feed-window rows when different sources share a subject id', function () {
        $since = now()->subDays(7);

        $run = function (string $id, int $sourceRowId, string $source, int $sourceSubjectId, string $status, \Carbon\CarbonInterface $occurredAt): QueryBuilder {
            return DB::query()
                ->selectRaw('? as id', [$id])
                ->selectRaw('? as source_row_id', [$sourceRowId])
                ->selectRaw('? as source', [$source])
                ->selectRaw('? as source_subject_id', [$sourceSubjectId])
                ->selectRaw('? as normalized_status', [$status])
                ->selectRaw('1 as current_monitoring_enabled')
                ->selectRaw('? as subject', ["{$source} subject"])
                ->selectRaw('? as subject_id', [$sourceSubjectId])
                ->selectRaw('NULL as component_id')
                ->selectRaw('NULL as component_name')
                ->selectRaw('? as summary', ["{$source} summary"])
                ->selectRaw('NULL as cause_key')
                ->selectRaw('? as occurred_at', [$occurredAt->toDateTimeString()]);
        };

        $baseRuns = $run('website-prior', 10, 'website', 42, 'healthy', $since->copy()->subDays(2))
            ->unionAll($run('api-prior', 20, 'api', 42, 'danger', $since->copy()->subDay()))
            ->unionAll($run('website-window', 30, 'website', 42, 'danger', $since->copy()->addHour()));

        $method = new ReflectionMethod(IncidentFeedWidget::class, 'limitRunsToFeedWindow');
        $limitedRuns = $method->invoke(null, $baseRuns, $since);

        expect($limitedRuns->pluck('id')->all())
            ->toContain('website-prior')
            ->toContain('api-prior')
            ->toContain('website-window');
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
            ->assertSee('Billing webhook returned 500')
            ->assertSee('Heartbeat received successfully.')
            ->assertSee('RECOVERED')
            ->assertSee('Resolved');
    });

    it('opens the exact API result evidence row from an incident', function () {
        $this->travelTo('2026-05-21 12:00:00');

        $api = MonitorApis::factory()->create([
            'created_by' => $this->user->id,
            'title' => 'Evidence API',
        ]);

        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $api->id,
            'created_at' => now()->subMinutes(20),
        ]);
        $result = MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'status' => 'danger',
            'summary' => 'Evidence API failed an assertion',
            'http_code' => 200,
            'response_time_ms' => 431,
            'failed_assertions' => [[
                'path' => 'data.status',
                'type' => 'value_compare',
                'message' => 'Expected active status.',
                'actual' => 'pending',
                'expected' => 'active',
            ]],
            'created_at' => now()->subMinutes(10),
        ]);
        MonitorApiResult::factory()->failed()->create([
            'monitor_api_id' => $api->id,
            'created_at' => now()->subMinutes(4),
        ]);

        $incident = IncidentFeedWidget::buildIncidentsQueryFor($this->user->id, now()->subDays(7))
            ->where('source', 'api')
            ->first();

        expect($incident)->not->toBeNull()
            ->and($incident->source_row_id)->toBe($result->id);

        Livewire::test(IncidentFeedWidget::class)
            ->assertTableActionExists('viewEvidence', null, $incident->getKey());

        $html = view('filament.widgets.incident-feed-evidence-modal', [
            'incident' => $incident,
            'evidence' => $result,
            'targetUrl' => null,
        ])->render();

        expect($html)
            ->toContain('Source row #'.$result->id)
            ->toContain('Scheduled streak')
            ->toContain('2 failures')
            ->toContain('May 21, 2026 11:50 AM')
            ->toContain('Failed Assertions')
            ->toContain('Expected active status.');
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

        Livewire::test(IncidentFeedWidget::class)
            ->assertSee('Slow response')
            ->assertSee('Checkout failing');
    });
});
