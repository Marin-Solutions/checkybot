<?php

namespace App\Console\Commands;

use App\Crawlers\SimpleSeoCrawler;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Console\Command;

class TestSimpleCrawler extends Command
{
    protected $signature = 'test:simple-crawler';
    protected $description = 'Test the SimpleSeoCrawler directly';

    public function handle()
    {
        $this->info('Testing SimpleSeoCrawler...');

        try {
            $website = Website::where('url', 'https://laravel.com')->first();
            if (!$website) {
                $this->error('Website not found');
                return;
            }

            $seoCheck = SeoCheck::create([
                'website_id' => $website->id,
                'status' => 'pending',
                'started_at' => now(),
            ]);

            $this->info("Created SEO Check ID: {$seoCheck->id}");

            $crawler = new SimpleSeoCrawler($seoCheck);
            $this->info('SimpleSeoCrawler instantiated successfully');

            // Test if we can call the methods
            $this->info('Testing method signatures...');

            // This should not throw an error
            $reflection = new \ReflectionClass($crawler);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                $this->info("Method: {$method->getName()}");
            }
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . ":" . $e->getLine());
        }
    }
}

