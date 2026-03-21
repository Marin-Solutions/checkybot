<?php

namespace App\Console\Commands;

use App\Services\ProjectComponentStaleService;
use Illuminate\Console\Command;

class CheckProjectComponentStaleStatus extends Command
{
    protected $signature = 'project-components:check-stale';

    protected $description = 'Mark overdue project components as stale and record stale history';

    public function __construct(
        protected ProjectComponentStaleService $projectComponentStaleService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->projectComponentStaleService->markStaleComponents();

        $this->info("Marked {$count} project components as stale.");

        return self::SUCCESS;
    }
}
