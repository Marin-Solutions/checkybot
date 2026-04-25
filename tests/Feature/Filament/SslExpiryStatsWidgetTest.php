<?php

use App\Filament\Widgets\SslExpiryStatsWidget;
use App\Models\User;
use App\Models\Website;
use Livewire\Livewire;

describe('SslExpiryStatsWidget', function () {
    beforeEach(function () {
        $this->user = $this->actingAsSuperAdmin();
    });

    it('renders without errors', function () {
        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSuccessful();
    });

    it('shows zero counts when the user has no websites', function () {
        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSee('SSL expired')
            ->assertSee('Expiring in 7 days')
            ->assertSee('Expiring in 14 days')
            ->assertSee('Expiring in 30 days')
            ->assertSee('No expired certificates');
    });

    it('counts websites with certificates that have already expired', function () {
        Website::factory()->count(2)->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->subDays(3)->toDateString(),
        ]);

        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(60)->toDateString(),
        ]);

        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSee('Renew immediately');
    });

    it('counts certificates expiring within the next 7 days', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(3)->toDateString(),
        ]);

        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        $widget = new SslExpiryStatsWidget;

        $stats = collect((fn () => $this->getStats())->call($widget));

        $within7 = $stats->first(fn ($stat) => $stat->getLabel() === 'Expiring in 7 days');
        $within14 = $stats->first(fn ($stat) => $stat->getLabel() === 'Expiring in 14 days');
        $within30 = $stats->first(fn ($stat) => $stat->getLabel() === 'Expiring in 30 days');

        expect($within7->getValue())->toBe(1)
            ->and($within14->getValue())->toBe(1)
            ->and($within30->getValue())->toBe(2);
    });

    it('treats the 14 and 30 day buckets as cumulative windows', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(2)->toDateString(),
        ]);
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(10)->toDateString(),
        ]);
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(25)->toDateString(),
        ]);
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(120)->toDateString(),
        ]);

        $widget = new SslExpiryStatsWidget;
        $stats = collect((fn () => $this->getStats())->call($widget));

        expect($stats->first(fn ($s) => $s->getLabel() === 'Expiring in 7 days')->getValue())->toBe(1)
            ->and($stats->first(fn ($s) => $s->getLabel() === 'Expiring in 14 days')->getValue())->toBe(2)
            ->and($stats->first(fn ($s) => $s->getLabel() === 'Expiring in 30 days')->getValue())->toBe(3);
    });

    it('ignores websites where ssl_check is disabled', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => false,
            'ssl_expiry_date' => now()->subDays(1)->toDateString(),
        ]);

        $widget = new SslExpiryStatsWidget;
        $stats = collect((fn () => $this->getStats())->call($widget));

        expect($stats->first(fn ($s) => $s->getLabel() === 'SSL expired')->getValue())->toBe(0);
    });

    it('ignores websites with no recorded ssl_expiry_date', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => null,
        ]);

        $widget = new SslExpiryStatsWidget;
        $stats = collect((fn () => $this->getStats())->call($widget));

        expect($stats->first(fn ($s) => $s->getLabel() === 'SSL expired')->getValue())->toBe(0)
            ->and($stats->first(fn ($s) => $s->getLabel() === 'Expiring in 30 days')->getValue())->toBe(0);
    });

    it('scopes counts to the currently authenticated user', function () {
        $otherUser = User::factory()->create();

        Website::factory()->create([
            'created_by' => $otherUser->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->subDays(2)->toDateString(),
        ]);
        Website::factory()->create([
            'created_by' => $otherUser->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(3)->toDateString(),
        ]);

        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(5)->toDateString(),
        ]);

        $widget = new SslExpiryStatsWidget;
        $stats = collect((fn () => $this->getStats())->call($widget));

        expect($stats->first(fn ($s) => $s->getLabel() === 'SSL expired')->getValue())->toBe(0)
            ->and($stats->first(fn ($s) => $s->getLabel() === 'Expiring in 7 days')->getValue())->toBe(1);
    });
});

it('dashboard page renders the SSL expiry stats widget for a super admin', function () {
    $user = $this->actingAsSuperAdmin();

    Website::factory()->create([
        'created_by' => $user->id,
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(4)->toDateString(),
    ]);

    $this->get('/admin')
        ->assertSuccessful()
        ->assertSeeLivewire(SslExpiryStatsWidget::class);
});
