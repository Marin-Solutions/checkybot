<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckProjectComponentStaleStatus extends Command
{
    protected $signature = 'project-components:check-stale';

    protected $description = 'Deprecated no-op: application component health is derived from active child checks';

    public function handle(): int
    {
        $this->info('Project component stale detection is disabled; component health is derived from active child checks.');

        return self::SUCCESS;
    }
}
