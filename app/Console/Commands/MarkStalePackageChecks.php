<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarkStalePackageChecks extends Command
{
    protected $signature = 'app:mark-stale-package-checks';

    protected $description = 'Deprecated no-op: package-managed check health is updated by real Checkybot executions';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
