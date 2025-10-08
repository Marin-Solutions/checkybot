<?php

namespace App\Console\Commands;

use App\Models\Website;
use Illuminate\Console\Command;

class AddTestWebsite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'add:test-website {url}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add a test website for SEO checking';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->argument('url');

        $website = Website::create([
            'url' => $url,
            'name' => parse_url($url, PHP_URL_HOST),
            'status' => 'active',
            'created_by' => 1, // Default user ID
        ]);

        $this->info("Created website: {$website->name} (ID: {$website->id})");

        return Command::SUCCESS;
    }
}
