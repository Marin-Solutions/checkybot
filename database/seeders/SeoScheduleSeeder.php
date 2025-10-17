<?php

namespace Database\Seeders;

use App\Models\SeoSchedule;
use App\Models\Website;
use Illuminate\Database\Seeder;

class SeoScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first 5 real websites (not fake ones)
        $websites = Website::whereIn('url', [
            'https://laravel.com',
            'https://github.com',
            'https://stackoverflow.com',
            'https://developer.mozilla.org',
            'https://tailwindcss.com',
        ])->get();

        foreach ($websites as $website) {
            SeoSchedule::firstOrCreate(
                ['website_id' => $website->id],
                [
                    'frequency' => 'weekly',
                    'next_run_at' => now()->addWeek(),
                    'is_active' => true,
                ]
            );
        }

        // Create some daily schedules for other websites
        $otherWebsites = Website::whereNotIn('url', [
            'https://laravel.com',
            'https://github.com',
            'https://stackoverflow.com',
            'https://developer.mozilla.org',
            'https://tailwindcss.com',
        ])->take(3)->get();

        foreach ($otherWebsites as $website) {
            SeoSchedule::firstOrCreate(
                ['website_id' => $website->id],
                [
                    'frequency' => 'daily',
                    'next_run_at' => now()->addDay(),
                    'is_active' => true,
                ]
            );
        }
    }
}
