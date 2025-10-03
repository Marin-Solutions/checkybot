<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;

class SeoTestWebsitesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Use the super admin user
        $user = User::where('email', 'superadmin@checkybot.com')->first();

        // Add real websites for SEO testing
        $testWebsites = [
            [
                'name' => 'Laravel Documentation',
                'url' => 'https://laravel.com',
                'description' => 'Official Laravel PHP framework documentation and guides.',
            ],
            [
                'name' => 'GitHub',
                'url' => 'https://github.com',
                'description' => 'GitHub - where the world builds software.',
            ],
            [
                'name' => 'Stack Overflow',
                'url' => 'https://stackoverflow.com',
                'description' => 'Stack Overflow - Where Developers Learn, Share, & Build Careers.',
            ],
            [
                'name' => 'MDN Web Docs',
                'url' => 'https://developer.mozilla.org',
                'description' => 'MDN Web Docs - Resources for developers, by developers.',
            ],
            [
                'name' => 'HTTPBin',
                'url' => 'https://httpbin.org',
                'description' => 'HTTPBin - A simple HTTP Request & Response Service for testing.',
            ],
        ];

        foreach ($testWebsites as $websiteData) {
            Website::firstOrCreate(
                ['url' => $websiteData['url']],
                array_merge($websiteData, [
                    'created_by' => $user->id,
                ])
            );
        }

        $this->command->info('Added '.count($testWebsites).' test websites for SEO crawling.');
    }
}
