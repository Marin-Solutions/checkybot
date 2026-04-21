<?php

use App\Filament\Pages\Documentation;
use App\Filament\Widgets\DeveloperDocumentationWidget;
use Livewire\Livewire;

test('super admin can render developer documentation page', function () {
    $this->actingAsSuperAdmin();

    config()->set('app.url', 'https://checkybot.example.com');

    Livewire::test(Documentation::class)
        ->assertSuccessful()
        ->assertSee('Developer documentation')
        ->assertSee('MCP configuration')
        ->assertSee('https://checkybot.example.com/api/v1/mcp')
        ->assertSee('GET /control/projects')
        ->assertSee('upsert_check');
});

test('developer documentation widget links setup entry points', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(DeveloperDocumentationWidget::class)
        ->assertSuccessful()
        ->assertSee('Developer setup')
        ->assertSee('Open documentation')
        ->assertSee('Manage API keys');
});
