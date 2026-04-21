<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class DeveloperDocumentationWidget extends Widget
{
    protected string $view = 'filament.widgets.developer-documentation-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['Super Admin', 'Admin']) ?? false;
    }
}
