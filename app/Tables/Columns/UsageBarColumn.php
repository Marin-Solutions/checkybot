<?php

namespace App\Tables\Columns;

use Filament\Tables\Columns\ViewColumn;

class UsageBarColumn extends ViewColumn
{
    protected string $view = 'tables.columns.usage-bar-column';

    protected bool $isHtml = true;
}
