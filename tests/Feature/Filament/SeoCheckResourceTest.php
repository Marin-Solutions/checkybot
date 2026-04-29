<?php

use App\Filament\Resources\SeoCheckResource\Pages\ViewSeoCheck;
use App\Models\SeoCheck;
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
