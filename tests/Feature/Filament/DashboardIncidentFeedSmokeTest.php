<?php

use App\Filament\Widgets\IncidentFeedWidget;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

it('dashboard page leaves incident drilldown to the health overview', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Dashboard smoke test site',
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'danger',
        'summary' => 'Dashboard smoke test failure',
        'created_at' => now()->subMinute(),
    ]);

    $this->get('/admin')
        ->assertSuccessful()
        ->assertDontSeeLivewire(IncidentFeedWidget::class);
});

it('keeps incident feed schema discovery state updateable across livewire requests', function () {
    $this->actingAsSuperAdmin();

    $property = new ReflectionProperty(IncidentFeedWidget::class, 'discoveredSchemaNames');

    expect($property->getAttributes(Locked::class))->toBeEmpty();

    Livewire::test(IncidentFeedWidget::class)
        ->set('discoveredSchemaNames', ['table'])
        ->assertSet('discoveredSchemaNames', ['table']);
});
