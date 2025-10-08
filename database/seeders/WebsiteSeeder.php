<?php

namespace Database\Seeders;

use App\Models\Website;
use Illuminate\Database\Seeder;

class WebsiteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create some real websites for testing
        $websites = [
            [
                'name' => 'Laravel Official',
                'url' => 'https://laravel.com',
                'description' => 'The official Laravel framework website',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'GitHub',
                'url' => 'https://github.com',
                'description' => 'GitHub - The world\'s leading software development platform',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'Stack Overflow',
                'url' => 'https://stackoverflow.com',
                'description' => 'Stack Overflow - Where Developers Learn, Share, & Build Careers',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'MDN Web Docs',
                'url' => 'https://developer.mozilla.org',
                'description' => 'MDN Web Docs - Resources for developers, by developers',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'Tailwind CSS',
                'url' => 'https://tailwindcss.com',
                'description' => 'Tailwind CSS - A utility-first CSS framework',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'Vue.js',
                'url' => 'https://vuejs.org',
                'description' => 'Vue.js - The Progressive JavaScript Framework',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'React',
                'url' => 'https://reactjs.org',
                'description' => 'React - A JavaScript library for building user interfaces',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'Node.js',
                'url' => 'https://nodejs.org',
                'description' => 'Node.js - JavaScript runtime built on Chrome\'s V8 JavaScript engine',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'PHP.net',
                'url' => 'https://php.net',
                'description' => 'PHP - Hypertext Preprocessor official documentation',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
            [
                'name' => 'WordPress',
                'url' => 'https://wordpress.org',
                'description' => 'WordPress - Open source software you can use to create a website',
                'created_by' => 1,
                'uptime_check' => true,
                'uptime_interval' => 1,
                'ssl_check' => true,
            ],
        ];

        foreach ($websites as $websiteData) {
            Website::firstOrCreate(
                ['url' => $websiteData['url']],
                $websiteData
            );
        }

        // Also create some fake websites for variety
        Website::factory()
            ->count(20)
            ->create();
    }
}
