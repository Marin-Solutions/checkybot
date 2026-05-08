<?php

use App\Filament\Resources\SeoCheckResource;
use App\Filament\Resources\SeoCheckResource\Pages\ListSeoChecks;
use App\Filament\Resources\SeoCheckResource\Pages\ViewSeoCheck;
use App\Filament\Resources\WebsiteSeoCheckResource\Pages\ListWebsiteSeoChecks;
use App\Filament\Widgets\SeoHealthScoreTrendWidget;
use App\Filament\Widgets\SeoIssuesTableWidget;
use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Policies\SeoCheckPolicy;
use App\Services\RobotsSitemapService;
use App\Services\SeoHealthCheckService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('website seo checks list includes websites without previous checks', function () {
    $user = $this->actingAsSuperAdmin();

    $firstRunWebsite = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'First run site',
        'url' => 'https://first-run.example.com',
    ]);
    $previouslyCheckedWebsite = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Checked site',
        'url' => 'https://checked.example.com',
    ]);
    SeoCheck::factory()->completed()->create([
        'website_id' => $previouslyCheckedWebsite->id,
    ]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->assertCanSeeTableRecords([$firstRunWebsite, $previouslyCheckedWebsite])
        ->assertTableActionVisible('run_seo_check', $firstRunWebsite)
        ->assertTableActionHidden('view_latest_progress', $firstRunWebsite);
});

test('website seo checks list only includes websites owned by the current user', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();

    $ownWebsite = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Own SEO site',
    ]);
    $otherWebsite = Website::factory()->create([
        'created_by' => $otherUser->id,
        'name' => 'Other SEO site',
    ]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->assertCanSeeTableRecords([$ownWebsite])
        ->assertCanNotSeeTableRecords([$otherWebsite]);
});

test('website seo checks list exposes seo schedule automation columns', function () {
    $user = $this->actingAsSuperAdmin();
    $nextRunAt = now()->addDays(3)->setTime(9, 30);
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Scheduled SEO site',
    ]);

    SeoSchedule::factory()->weekly()->create([
        'website_id' => $website->id,
        'created_by' => $user->id,
        'is_active' => true,
        'next_run_at' => $nextRunAt,
    ]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->assertCanSeeTableRecords([$website])
        ->assertTableColumnExists('seoSchedule.is_active')
        ->assertTableColumnExists('seoSchedule.frequency')
        ->assertTableColumnExists('seoSchedule.next_run_at')
        ->assertSee('Enabled')
        ->assertSee('Weekly');
});

test('website seo checks list filters by seo schedule state', function () {
    $user = $this->actingAsSuperAdmin();
    $enabled = Website::factory()->create(['created_by' => $user->id, 'name' => 'Enabled SEO automation']);
    $disabled = Website::factory()->create(['created_by' => $user->id, 'name' => 'Disabled SEO automation']);
    $notConfigured = Website::factory()->create(['created_by' => $user->id, 'name' => 'Manual SEO checks only']);

    SeoSchedule::factory()->create([
        'website_id' => $enabled->id,
        'created_by' => $user->id,
        'is_active' => true,
    ]);
    SeoSchedule::factory()->inactive()->create([
        'website_id' => $disabled->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_state', 'enabled')
        ->assertCanSeeTableRecords([$enabled])
        ->assertCanNotSeeTableRecords([$disabled, $notConfigured]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_state', 'disabled')
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$enabled, $notConfigured]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_state', 'not_configured')
        ->assertCanSeeTableRecords([$notConfigured])
        ->assertCanNotSeeTableRecords([$enabled, $disabled]);
});

test('website seo checks list filters by seo schedule frequency', function () {
    $user = $this->actingAsSuperAdmin();
    $daily = Website::factory()->create(['created_by' => $user->id, 'name' => 'Daily SEO site']);
    $weekly = Website::factory()->create(['created_by' => $user->id, 'name' => 'Weekly SEO site']);
    $monthly = Website::factory()->create(['created_by' => $user->id, 'name' => 'Monthly SEO site']);
    $notConfigured = Website::factory()->create(['created_by' => $user->id, 'name' => 'Unscheduled SEO site']);

    SeoSchedule::factory()->daily()->create(['website_id' => $daily->id, 'created_by' => $user->id]);
    SeoSchedule::factory()->weekly()->create(['website_id' => $weekly->id, 'created_by' => $user->id]);
    SeoSchedule::factory()->monthly()->create(['website_id' => $monthly->id, 'created_by' => $user->id]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_frequency', 'weekly')
        ->assertCanSeeTableRecords([$weekly])
        ->assertCanNotSeeTableRecords([$daily, $monthly, $notConfigured]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_frequency', 'not_configured')
        ->assertCanSeeTableRecords([$notConfigured])
        ->assertCanNotSeeTableRecords([$daily, $weekly, $monthly]);
});

test('website seo checks list filters by seo schedule next run window', function () {
    $user = $this->actingAsSuperAdmin();
    $overdue = Website::factory()->create(['created_by' => $user->id, 'name' => 'Overdue SEO site']);
    $soon = Website::factory()->create(['created_by' => $user->id, 'name' => 'Soon SEO site']);
    $later = Website::factory()->create(['created_by' => $user->id, 'name' => 'Later SEO site']);
    $inactiveDue = Website::factory()->create(['created_by' => $user->id, 'name' => 'Inactive due SEO site']);
    $notScheduled = Website::factory()->create(['created_by' => $user->id, 'name' => 'No next run SEO site']);

    SeoSchedule::factory()->create([
        'website_id' => $overdue->id,
        'created_by' => $user->id,
        'next_run_at' => now()->subHour(),
    ]);
    SeoSchedule::factory()->create([
        'website_id' => $soon->id,
        'created_by' => $user->id,
        'next_run_at' => now()->addHours(6),
    ]);
    SeoSchedule::factory()->create([
        'website_id' => $later->id,
        'created_by' => $user->id,
        'next_run_at' => now()->addDays(10),
    ]);
    SeoSchedule::factory()->inactive()->create([
        'website_id' => $inactiveDue->id,
        'created_by' => $user->id,
        'next_run_at' => now()->subHour(),
    ]);
    SeoSchedule::factory()->create([
        'website_id' => $notScheduled->id,
        'created_by' => $user->id,
        'next_run_at' => null,
    ]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_next_run', 'overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$soon, $later, $inactiveDue, $notScheduled]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_next_run', 'next_24_hours')
        ->assertCanSeeTableRecords([$soon])
        ->assertCanNotSeeTableRecords([$overdue, $later, $inactiveDue, $notScheduled]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->filterTable('seo_schedule_next_run', 'not_scheduled')
        ->assertCanSeeTableRecords([$notScheduled])
        ->assertCanNotSeeTableRecords([$overdue, $soon, $later, $inactiveDue]);
});

test('seo check list only includes checks for websites owned by the current user', function () {
    $this->createResourcePermissions('SeoCheck');

    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['ViewAny:SeoCheck', 'View:SeoCheck']);
    $otherUser = User::factory()->create();

    $ownWebsite = Website::factory()->create(['created_by' => $user->id]);
    $otherWebsite = Website::factory()->create(['created_by' => $otherUser->id]);

    $ownSeoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $ownWebsite->id,
    ]);
    $otherSeoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $otherWebsite->id,
    ]);

    Livewire::test(ListSeoChecks::class)
        ->assertCanSeeTableRecords([$ownSeoCheck])
        ->assertCanNotSeeTableRecords([$otherSeoCheck]);
});

test('seo check policy requires ownership through the related website', function () {
    $this->createResourcePermissions('SeoCheck');

    $user = User::factory()->create();
    $user->givePermissionTo('View:SeoCheck');
    $otherUser = User::factory()->create();

    $ownWebsite = Website::factory()->create(['created_by' => $user->id]);
    $otherWebsite = Website::factory()->create(['created_by' => $otherUser->id]);

    $ownSeoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $ownWebsite->id,
    ]);
    $otherSeoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $otherWebsite->id,
    ]);

    $policy = app(SeoCheckPolicy::class);

    expect($policy->view($user, $ownSeoCheck))->toBeTrue()
        ->and($policy->view($user, $otherSeoCheck))->toBeFalse();
});

test('direct seo check route cannot open another users check', function () {
    $this->createResourcePermissions('SeoCheck');

    $user = $this->actingAsSuperAdmin();
    $user->givePermissionTo(['ViewAny:SeoCheck', 'View:SeoCheck']);
    $otherUser = User::factory()->create();
    $otherWebsite = Website::factory()->create(['created_by' => $otherUser->id]);
    $otherSeoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $otherWebsite->id,
    ]);

    $response = $this->get(route('filament.admin.resources.seo-checks.view', [
        'record' => $otherSeoCheck,
    ]));

    expect($response->status())->toBeIn([403, 404]);
});

test('seo health score trend widget is hidden outside seo check detail pages', function () {
    $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();
    $otherWebsite = Website::factory()->create(['created_by' => $otherUser->id]);

    SeoCheck::factory()->completed()->create([
        'website_id' => $otherWebsite->id,
        'finished_at' => now(),
        'computed_health_score' => 88.4,
    ]);

    expect(SeoHealthScoreTrendWidget::canView())->toBeFalse();

    $widget = new SeoHealthScoreTrendWidget;
    $data = (fn (): array => $this->getData())->call($widget);

    expect($data['datasets'][0]['data'])->toBe([])
        ->and($data['labels'])->toBe([]);
});

test('seo health score trend widget ignores unauthorized explicit website ids', function () {
    $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();
    $otherWebsite = Website::factory()->create(['created_by' => $otherUser->id]);

    SeoCheck::factory()->completed()->create([
        'website_id' => $otherWebsite->id,
        'finished_at' => now(),
        'computed_health_score' => 94.2,
    ]);

    $widget = new SeoHealthScoreTrendWidget;
    $widget->websiteId = $otherWebsite->id;

    $data = (fn (): array => $this->getData())->call($widget);

    expect($data['datasets'][0]['data'])->toBe([])
        ->and($data['labels'])->toBe([]);
});

test('seo health score trend widget shows authorized website trend data', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
        'finished_at' => now()->subDay(),
        'computed_health_score' => 91.7,
    ]);

    $widget = new SeoHealthScoreTrendWidget;
    $widget->websiteId = $website->id;

    $data = (fn (): array => $this->getData())->call($widget);

    expect($data['datasets'][0]['data'])->toBe([91.7])
        ->and($data['labels'])->toBe([now()->subDay()->format('M j')]);
});

test('website seo checks list can start the first seo check for a website', function () {
    Queue::fake();

    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'First crawl site',
        'url' => 'https://first-crawl.example.com',
    ]);

    $robotsService = $this->mock(RobotsSitemapService::class);
    $robotsService->shouldReceive('getCrawlableUrls')
        ->once()
        ->with($website->url)
        ->andReturn([$website->url]);

    $component = Livewire::test(ListWebsiteSeoChecks::class)
        ->callTableAction('run_seo_check', $website)
        ->assertHasNoTableActionErrors();

    $seoCheck = SeoCheck::where('website_id', $website->id)->sole();

    $component->assertRedirect(SeoCheckResource::getUrl('view', [
        'record' => $seoCheck,
    ]));

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_PENDING)
        ->and($seoCheck->total_crawlable_urls)->toBe(1);

    Queue::assertPushed(SeoHealthCheckJob::class);
});

test('website seo checks list records failed manual start when no crawlable urls are found', function () {
    Queue::fake();

    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Blocked crawl site',
        'url' => 'https://blocked-crawl.example.com',
    ]);

    $robotsService = $this->mock(RobotsSitemapService::class);
    $robotsService->shouldReceive('getCrawlableUrls')
        ->once()
        ->with($website->url)
        ->andReturn([]);

    Livewire::test(ListWebsiteSeoChecks::class)
        ->callTableAction('run_seo_check', $website)
        ->assertHasNoTableActionErrors();

    Queue::assertNotPushed(SeoHealthCheckJob::class);

    $seoCheck = SeoCheck::where('website_id', $website->id)->sole();

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($seoCheck->failure_summary)->toBe(SeoHealthCheckService::NO_CRAWLABLE_URLS_FAILURE_SUMMARY)
        ->and($seoCheck->failure_context)->toMatchArray([
            'failure_reason' => 'no_crawlable_urls',
            'website_url' => $website->url,
            'manual_by' => $user->id,
        ])
        ->and($seoCheck->failure_context['checked_at'])->not->toBeEmpty()
        ->and($seoCheck->crawl_summary)->toMatchArray([
            'manual_by' => $user->id,
            'is_manual' => true,
            'failure_reason' => 'no_crawlable_urls',
            'summary' => SeoHealthCheckService::NO_CRAWLABLE_URLS_FAILURE_SUMMARY,
        ])
        ->and($seoCheck->robots_txt_checked)->toBeTrue()
        ->and($seoCheck->started_at)->not->toBeNull()
        ->and($seoCheck->finished_at)->not->toBeNull();
});

test('website seo checks list does not show the invalid create action', function () {
    $this->actingAsSuperAdmin();

    $page = Livewire::test(ListWebsiteSeoChecks::class)->instance();
    $reflection = new ReflectionMethod($page, 'getHeaderActions');
    $reflection->setAccessible(true);

    expect($reflection->invoke($page))->toBe([]);
});

test('all seo checks list does not show the invalid create action', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListSeoChecks::class)
        ->assertActionDoesNotExist('create');
});

test('website scoped seo checks list can start a check from the header', function () {
    Queue::fake();

    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Scoped crawl site',
        'url' => 'https://scoped-crawl.example.com',
    ]);

    $robotsService = $this->mock(RobotsSitemapService::class);
    $robotsService->shouldReceive('getCrawlableUrls')
        ->once()
        ->with($website->url)
        ->andReturn([$website->url]);

    $component = Livewire::withQueryParams(['website_id' => $website->id])
        ->test(ListSeoChecks::class)
        ->assertActionVisible('run_seo_check')
        ->assertActionDoesNotExist('create')
        ->mountAction('run_seo_check')
        ->assertActionMounted('run_seo_check')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $seoCheck = SeoCheck::where('website_id', $website->id)->sole();

    $component->assertRedirect(SeoCheckResource::getUrl('view', [
        'record' => $seoCheck,
    ]));

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_PENDING);

    Queue::assertPushed(SeoHealthCheckJob::class);
});

test('website scoped seo checks list records failed manual start when url discovery throws', function () {
    Queue::fake();

    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Discovery error site',
        'url' => 'https://discovery-error.example.com',
    ]);

    $robotsService = $this->mock(RobotsSitemapService::class);
    $robotsService->shouldReceive('getCrawlableUrls')
        ->once()
        ->with($website->url)
        ->andThrow(new RuntimeException('robots service unavailable'));

    Livewire::withQueryParams(['website_id' => $website->id])
        ->test(ListSeoChecks::class)
        ->callAction('run_seo_check')
        ->assertHasNoActionErrors();

    Queue::assertNotPushed(SeoHealthCheckJob::class);

    $seoCheck = SeoCheck::where('website_id', $website->id)->sole();

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($seoCheck->failure_summary)->toBe('Manual SEO check could not start: robots service unavailable')
        ->and($seoCheck->failure_context)->toMatchArray([
            'failure_reason' => 'manual_startup_failed',
            'website_url' => $website->url,
            'manual_by' => $user->id,
            'exception_class' => RuntimeException::class,
            'exception_message' => 'robots service unavailable',
        ])
        ->and($seoCheck->failure_context['checked_at'])->not->toBeEmpty()
        ->and($seoCheck->crawl_summary)->toMatchArray([
            'manual_by' => $user->id,
            'is_manual' => true,
            'failure_reason' => 'manual_startup_failed',
            'summary' => 'Manual SEO check could not start: robots service unavailable',
        ])
        ->and($seoCheck->robots_txt_checked)->toBeFalse()
        ->and($seoCheck->started_at)->not->toBeNull()
        ->and($seoCheck->finished_at)->not->toBeNull();
});

test('manual seo check marks pending check failed when dispatch throws', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Dispatch failure site',
        'url' => 'https://dispatch-failure.example.com',
    ]);

    $robotsService = $this->mock(RobotsSitemapService::class);
    $robotsService->shouldReceive('getCrawlableUrls')
        ->once()
        ->with($website->url)
        ->andReturn([$website->url]);

    $dispatcher = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);

    try {
        app(SeoHealthCheckService::class)->startManualCheck($website);
        $this->fail('Expected manual SEO check dispatch to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('queue unavailable');
    }

    $seoCheck = SeoCheck::where('website_id', $website->id)->sole();

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_FAILED)
        ->and($seoCheck->failure_summary)->toBe('Manual SEO check could not be dispatched: queue unavailable')
        ->and($seoCheck->failure_context)->toMatchArray([
            'failure_reason' => 'manual_dispatch_failed',
            'website_url' => $website->url,
            'manual_by' => $user->id,
            'exception_class' => RuntimeException::class,
            'exception_message' => 'queue unavailable',
            'seo_check_id' => $seoCheck->id,
        ])
        ->and($seoCheck->crawl_summary)->toMatchArray([
            'manual_by' => $user->id,
            'is_manual' => true,
            'failure_reason' => 'manual_dispatch_failed',
            'summary' => 'Manual SEO check could not be dispatched: queue unavailable',
        ])
        ->and($seoCheck->robots_txt_checked)->toBeTrue()
        ->and($seoCheck->total_crawlable_urls)->toBe(1)
        ->and($seoCheck->started_at)->not->toBeNull()
        ->and($seoCheck->finished_at)->not->toBeNull();
});

test('view seo check page shows failure details for failed checks', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Docs site',
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->failed()->create([
        'website_id' => $website->id,
        'failure_summary' => 'Blocked by robots.txt',
        'failure_context' => [
            'exception' => 'GuzzleHttp\\Exception\\RequestException',
            'failed_url' => 'https://example.com/blocked',
            'method' => 'GET',
            'total_urls_crawled' => 3,
        ],
    ]);

    Livewire::test(ViewSeoCheck::class, ['record' => $seoCheck->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Failure Details')
        ->assertSee('Blocked by robots.txt')
        ->assertSee('https://example.com/blocked')
        ->assertSee('Failed URL')
        ->assertSee('URLs Crawled');
});

test('view seo check page shows live progress section for pending checks', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Queued site',
        'url' => 'https://queued.example.com',
    ]);
    $seoCheck = SeoCheck::factory()->create([
        'website_id' => $website->id,
        'status' => SeoCheck::STATUS_PENDING,
    ]);

    Livewire::test(ViewSeoCheck::class, ['record' => $seoCheck->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Live Progress')
        ->assertSee('SEO Health Check Pending')
        ->assertSee('SEO Health Check is pending.')
        ->assertSeeHtml('display: block;" class="completion-section"')
        ->assertDontSee('SEO Health Check in Progress');
});

test('seo issue table exposes issue detail action with evidence and fix guidance', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Docs site',
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
    ]);
    $crawlResult = SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/docs',
        'status_code' => 200,
        'title' => 'Docs',
        'meta_description' => null,
        'response_time_ms' => 143.25,
        'internal_link_count' => 2,
    ]);
    $issue = SeoIssue::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'seo_crawl_result_id' => $crawlResult->id,
        'type' => 'broken_internal_link',
        'url' => 'https://example.com/docs',
        'title' => 'Broken Internal Link',
        'description' => 'Internal link to docs/missing returns 404 error',
        'data' => [
            'broken_url' => 'https://example.com/docs/missing',
            'status_code' => 404,
            'link_text' => 'Missing docs',
            'recommendation' => 'Repair the broken link or remove it.',
        ],
    ]);

    Livewire::test(SeoIssuesTableWidget::class, ['recordId' => $seoCheck->id])
        ->assertTableActionExists('view_issue_details', null, $issue)
        ->assertTableActionHasLabel('view_issue_details', 'View Details', $issue)
        ->mountTableAction('view_issue_details', $issue)
        ->assertHasNoTableActionErrors()
        ->assertSchemaStateSet([
            'evidence_items' => [
                [
                    'label' => 'Issue',
                    'value' => 'Broken Internal Link',
                ],
                [
                    'label' => 'Description',
                    'value' => 'Internal link to docs/missing returns 404 error',
                ],
                [
                    'label' => 'HTTP status',
                    'value' => '200',
                ],
                [
                    'label' => 'Response time',
                    'value' => '143.25ms',
                ],
                [
                    'label' => 'Page title',
                    'value' => 'Docs',
                ],
                [
                    'label' => 'Meta description',
                    'value' => 'Missing',
                ],
                [
                    'label' => 'Internal links',
                    'value' => '2',
                ],
                [
                    'label' => 'Broken Url',
                    'value' => 'https://example.com/docs/missing',
                ],
                [
                    'label' => 'Status Code',
                    'value' => '404',
                ],
                [
                    'label' => 'Link Text',
                    'value' => 'Missing docs',
                ],
            ],
            'fix_guidance' => [
                'Repair the broken link or remove it.',
                'Update or remove the link on the flagged page.',
                'If the target should exist, restore it or add a redirect to the correct destination.',
                'Run the SEO check again after the target returns a successful HTTP status.',
            ],
            'affected_urls' => [
                [
                    'label' => 'Flagged page',
                    'url' => 'https://example.com/docs',
                ],
                [
                    'label' => 'Broken target',
                    'url' => 'https://example.com/docs/missing',
                ],
            ],
            'data' => [
                'broken_url' => 'https://example.com/docs/missing',
                'status_code' => '404',
                'link_text' => 'Missing docs',
                'recommendation' => 'Repair the broken link or remove it.',
            ],
        ]);
});

test('seo issue table sorts by severity priority by default', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
    ]);

    $notice = SeoIssue::factory()->notice()->create([
        'seo_check_id' => $seoCheck->id,
        'title' => 'Notice issue',
    ]);
    $warning = SeoIssue::factory()->warning()->create([
        'seo_check_id' => $seoCheck->id,
        'title' => 'Warning issue',
    ]);
    $error = SeoIssue::factory()->error()->create([
        'seo_check_id' => $seoCheck->id,
        'title' => 'Error issue',
    ]);

    Livewire::test(SeoIssuesTableWidget::class, ['recordId' => $seoCheck->id])
        ->assertCanSeeTableRecords([$error, $warning, $notice], inOrder: true);
});

test('seo issue details keep affected url roles when values match', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
    ]);
    $issue = SeoIssue::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'seo_crawl_result_id' => null,
        'type' => 'redirect_loop',
        'url' => 'https://example.com/loop',
        'title' => 'Redirect Loop Detected',
        'description' => 'Page redirects to itself, creating an infinite loop',
        'data' => [
            'redirect_to' => 'https://example.com/loop',
            'status_code' => 301,
        ],
    ]);

    Livewire::test(SeoIssuesTableWidget::class, ['recordId' => $seoCheck->id])
        ->mountTableAction('view_issue_details', $issue)
        ->assertHasNoTableActionErrors()
        ->assertSchemaStateSet([
            'affected_urls' => [
                [
                    'label' => 'Flagged page',
                    'url' => 'https://example.com/loop',
                ],
                [
                    'label' => 'Redirect target',
                    'url' => 'https://example.com/loop',
                ],
            ],
        ]);
});
