<?php

namespace App\Providers;

use App\Livewire\FilamentNotificationsCollectionSynth;
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

        Gate::define('viewPulse', function ($user) {
            return $user->email === 'superadmin@nxtyou.de';
        });
    }
}
