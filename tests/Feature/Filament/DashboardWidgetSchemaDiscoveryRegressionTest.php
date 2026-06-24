<?php

use App\Filament\Resources\Projects\Widgets\ProjectHealthOverviewWidget;
use App\Filament\Resources\Projects\Widgets\ProjectIncidentFeedWidget;
use App\Filament\Resources\ServerResource\Widgets\ServerLogTimeframe;
use App\Filament\Widgets\ApiHealthStatsWidget;
use App\Filament\Widgets\DashboardHealthOverviewWidget;
use App\Filament\Widgets\IncidentFeedWidget;
use App\Filament\Widgets\ProxyPoolStatsWidget;
use App\Filament\Widgets\SeoDashboardStatsWidget;
use App\Filament\Widgets\ServerHealthStatsWidget;
use App\Filament\Widgets\SslExpiryStatsWidget;
use App\Models\Project;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

it('keeps dashboard widget schema discovery state updateable across livewire polling requests', function (string $widgetClass, Closure $parameters, string $schemaName) {
    $user = $this->actingAsSuperAdmin();
    $params = $parameters($user);

    $schemaDiscoveryProperty = new ReflectionProperty($widgetClass, 'discoveredSchemaNames');
    $schemaTestingHooksProperty = new ReflectionProperty($widgetClass, 'areSchemaStateUpdateHooksDisabledForTesting');

    expect($schemaDiscoveryProperty->getAttributes(Locked::class))->toBeEmpty()
        ->and($schemaTestingHooksProperty->getAttributes(Locked::class))->toBeEmpty();

    Livewire::test($widgetClass, $params)
        ->set('discoveredSchemaNames', [$schemaName])
        ->set('areSchemaStateUpdateHooksDisabledForTesting', true)
        ->call('$refresh')
        ->assertSuccessful()
        ->assertSet('discoveredSchemaNames', [$schemaName])
        ->assertSet('areSchemaStateUpdateHooksDisabledForTesting', true);
})->with([
    'project health overview stats' => [
        ProjectHealthOverviewWidget::class,
        fn ($user): array => [
            'record' => Project::factory()->create(['created_by' => $user->id]),
        ],
        'content',
    ],
    'api health stats' => [
        ApiHealthStatsWidget::class,
        fn (): array => [],
        'content',
    ],
    'dashboard health overview stats' => [
        DashboardHealthOverviewWidget::class,
        fn (): array => [],
        'content',
    ],
    'ssl expiry stats' => [
        SslExpiryStatsWidget::class,
        fn (): array => [],
        'content',
    ],
    'proxy pool stats' => [
        ProxyPoolStatsWidget::class,
        fn (): array => [],
        'content',
    ],
    'server health stats' => [
        ServerHealthStatsWidget::class,
        fn (): array => [],
        'content',
    ],
    'seo dashboard stats' => [
        SeoDashboardStatsWidget::class,
        fn (): array => [],
        'content',
    ],
    'dashboard incident feed' => [
        IncidentFeedWidget::class,
        fn (): array => [],
        'table',
    ],
    'project incident feed' => [
        ProjectIncidentFeedWidget::class,
        fn ($user): array => [
            'record' => Project::factory()->create(['created_by' => $user->id]),
        ],
        'table',
    ],
    'server log timeframe' => [
        ServerLogTimeframe::class,
        fn (): array => [],
        'form',
    ],
]);
