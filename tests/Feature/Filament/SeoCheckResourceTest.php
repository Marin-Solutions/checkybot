<?php

use App\Filament\Resources\SeoCheckResource\Pages\ViewSeoCheck;
use App\Filament\Widgets\SeoIssuesTableWidget;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;
use Livewire\Livewire;

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
        ],
    ]);

    Livewire::test(SeoIssuesTableWidget::class, ['recordId' => $seoCheck->id])
        ->assertTableActionExists('view_issue_details', null, $issue)
        ->assertTableActionHasLabel('view_issue_details', 'View Details', $issue)
        ->mountTableAction('view_issue_details', $issue)
        ->assertHasNoTableActionErrors()
        ->assertSchemaStateSet([
            'fix_guidance' => [
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
            ],
        ]);
});
