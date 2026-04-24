<?php

use App\Filament\Widgets\IncidentFeedWidget;
use App\Models\Website;
use App\Models\WebsiteLogHistory;

it('dashboard page renders the incident feed widget for a super admin', function () {
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
        ->assertSeeLivewire(IncidentFeedWidget::class);
});
