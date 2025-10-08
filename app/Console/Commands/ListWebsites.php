<?php

namespace App\Console\Commands;

use App\Models\Website;
use Illuminate\Console\Command;

class ListWebsites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'list:websites';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all websites in the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $websites = Website::take(10)->get(['id', 'url']);

        $this->info('Websites in database:');
        foreach ($websites as $website) {
            $this->line("ID: {$website->id} - URL: {$website->url}");
        }

        return Command::SUCCESS;
    }
}
