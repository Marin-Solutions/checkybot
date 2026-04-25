<?php

namespace App\Providers;

use App\Filament\MyProfile\PersonalInfoWithTimezone;
use App\Livewire\FilamentNotificationsCollectionSynth;
use App\Support\UserTimezone;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::propertySynthesizer(FilamentNotificationsCollectionSynth::class);

        // Register the timezone-aware profile component under its own alias so
        // Livewire can rehydrate it across requests. Filament's profile page
        // resolves it by class, so we don't need to override Breezy's
        // 'personal_info' alias.
        Livewire::component('personal_info_with_timezone', PersonalInfoWithTimezone::class);

        Gate::define('viewPulse', function ($user) {
            return $user->email === 'superadmin@nxtyou.de';
        });

        $this->registerTimezoneAwareTextColumnMacros();
    }

    /**
     * Register convenience macros that pipe the current user's timezone into
     * Filament's TextColumn date formatters so distributed teams see incident
     * timestamps in their own timezone instead of the application default.
     */
    protected function registerTimezoneAwareTextColumnMacros(): void
    {
        $resolveTimezone = static fn (): ?string => UserTimezone::current();

        TextColumn::macro('dateTimeInUserZone', function (string|Closure|null $format = null) use ($resolveTimezone): TextColumn {
            /** @var TextColumn $this */
            return $this->dateTime($format, $resolveTimezone);
        });

        TextColumn::macro('sinceInUserZone', function () use ($resolveTimezone): TextColumn {
            /** @var TextColumn $this */
            return $this->since($resolveTimezone);
        });

        TextEntry::macro('dateTimeInUserZone', function (string|Closure|null $format = null) use ($resolveTimezone): TextEntry {
            /** @var TextEntry $this */
            return $this->dateTime($format, $resolveTimezone);
        });

        TextEntry::macro('sinceInUserZone', function () use ($resolveTimezone): TextEntry {
            /** @var TextEntry $this */
            return $this->since($resolveTimezone);
        });
    }
}
