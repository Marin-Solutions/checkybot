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

    it('shows an empty state when the user has no SSL-monitored websites', function () {
        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSee('SSL Monitoring')
            ->assertSee('No websites with SSL monitoring enabled')
            ->assertDontSee('SSL Expired');
    });

    it('shows the four expiry buckets when the user has at least one SSL-monitored website', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(60)->toDateString(),
        ]);

        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSee('SSL Expired')
            ->assertSee('Expiring Within 7 Days')
            ->assertSee('Expiring Within 14 Days')
            ->assertSee('Expiring Within 30 Days');
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

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'SSL Expired')->getValue())->toBe(2)
            ->and(sslStat($stats, 'SSL Expired')->getDescription())->toBe('Renew immediately');
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

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'Expiring Within 7 Days')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 14 Days')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 30 Days')->getValue())->toBe(2);
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

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'Expiring Within 7 Days')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 14 Days')->getValue())->toBe(2)
            ->and(sslStat($stats, 'Expiring Within 30 Days')->getValue())->toBe(3);
    });

    it('treats a certificate expiring today as still in the upcoming buckets, not expired', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->toDateString(),
        ]);

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'SSL Expired')->getValue())->toBe(0)
            ->and(sslStat($stats, 'Expiring Within 7 Days')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 14 Days')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 30 Days')->getValue())->toBe(1);
    });

    it('includes certificates expiring on the exact bucket boundary', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(7)->toDateString(),
        ]);
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(14)->toDateString(),
        ]);
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(30)->toDateString(),
        ]);

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'Expiring Within 7 Days')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 14 Days')->getValue())->toBe(2)
            ->and(sslStat($stats, 'Expiring Within 30 Days')->getValue())->toBe(3);
    });

    it('treats yesterday as expired', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->subDay()->toDateString(),
        ]);

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'SSL Expired')->getValue())->toBe(1)
            ->and(sslStat($stats, 'Expiring Within 7 Days')->getValue())->toBe(0);
    });

    it('ignores websites where ssl_check is disabled', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => false,
            'ssl_expiry_date' => now()->subDays(1)->toDateString(),
        ]);

        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSee('No websites with SSL monitoring enabled');
    });

    it('ignores websites with no recorded ssl_expiry_date', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => null,
        ]);

        Livewire::test(SslExpiryStatsWidget::class)
            ->assertSee('No websites with SSL monitoring enabled');
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

        $stats = collect(getSslExpiryStats(new SslExpiryStatsWidget));

        expect(sslStat($stats, 'SSL Expired')->getValue())->toBe(0)
            ->and(sslStat($stats, 'Expiring Within 7 Days')->getValue())->toBe(1);
    });

    it('renders on the dashboard page for a super admin', function () {
        Website::factory()->create([
            'created_by' => $this->user->id,
            'ssl_check' => true,
            'ssl_expiry_date' => now()->addDays(4)->toDateString(),
        ]);

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSeeLivewire(SslExpiryStatsWidget::class);
    });
});

/**
 * Invoke the protected getStats() method on the widget so tests can assert
 * on individual Stat values without spinning up Livewire.
 *
 * @return array<int, \Filament\Widgets\StatsOverviewWidget\Stat>
 */
function getSslExpiryStats(SslExpiryStatsWidget $widget): array
{
    return (fn () => $this->getStats())->call($widget);
}

/**
 * Find a Stat in the collection by its label. Throws if the label is missing
 * so a typo'd assertion surfaces a clear error instead of a null dereference.
 */
function sslStat(\Illuminate\Support\Collection $stats, string $label): \Filament\Widgets\StatsOverviewWidget\Stat
{
    $stat = $stats->first(fn ($candidate) => $candidate->getLabel() === $label);

    if ($stat === null) {
        throw new \RuntimeException("No SSL stat found with label '{$label}'.");
    }

    return $stat;
}
