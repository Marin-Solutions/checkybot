<?php

use App\Filament\Resources\SeoCheckResource\Pages\ListSeoChecks;
use App\Filament\Resources\SeoCheckResource\Pages\ViewSeoCheck;
use App\Filament\Resources\WebsiteSeoCheckResource\Pages\ListWebsiteSeoChecks;
use App\Filament\Widgets\SeoHealthScoreTrendWidget;
use App\Filament\Widgets\SeoIssuesTableWidget;
use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\User;
use App\Models\Website;
use App\Policies\SeoCheckPolicy;
use App\Services\RobotsSitemapService;
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

    Livewire::test(ListWebsiteSeoChecks::class)
        ->callTableAction('run_seo_check', $website)
        ->assertHasNoTableActionErrors();

    $seoCheck = SeoCheck::where('website_id', $website->id)->sole();

    expect($seoCheck->status)->toBe(SeoCheck::STATUS_PENDING)
        ->and($seoCheck->total_crawlable_urls)->toBe(1);

    Queue::assertPushed(SeoHealthCheckJob::class);
});

test('website seo checks list does not show the invalid create action', function () {
    $this->actingAsSuperAdmin();

    $page = Livewire::test(ListWebsiteSeoChecks::class)->instance();
    $reflection = new ReflectionMethod($page, 'getHeaderActions');
    $reflection->setAccessible(true);

    expect($reflection->invoke($page))->toBe([]);
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
