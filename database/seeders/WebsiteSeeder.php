<?php

namespace Database\Seeders;

use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class WebsiteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $websitesData = [
            [
                'name' => 'Demo Website',
                'url' => 'www.demowebsite.de',
                'description' => 'demo free test',
                'created_by' => 1,
            ],
            [
                'name' => 'Spatie website',
                'url' => 'www.spatie.be',
                'description' => 'spatie test data',
                'created_by' => 1,
            ],
        ];

        foreach ($websitesData as $websiteData) {
            $website = Website::create([
                'name' => $websiteData['name'],
                'url' => $websiteData['url'],
                'description' => $websiteData['description'],
                'created_by' => $websiteData['created_by'],
            ]);
        }
    }
}
