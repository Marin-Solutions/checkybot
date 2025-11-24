<?php

namespace App\Tables\Columns;

use Filament\Tables\Columns\Column;

class UsageBarColumn extends Column
{
    protected string $view = 'tables.columns.usage-bar-column';

    protected function setUp(): void
    {
        parent::setUp();
    }
}
