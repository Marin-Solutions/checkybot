<?php

namespace App\Tables\Columns;

use Filament\Tables\Columns\Column;

class SparklineColumn extends Column
{
    protected string $view = 'tables.columns.sparkline-column';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extraAttributes([
            'class' => 'px-4 py-3'
        ]);
    }
} 