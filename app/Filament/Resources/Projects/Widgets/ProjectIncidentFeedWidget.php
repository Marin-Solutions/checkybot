<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Filament\Widgets\IncidentFeedWidget;
use App\Models\Project;

class ProjectIncidentFeedWidget extends IncidentFeedWidget
{
    protected static ?string $heading = 'Recent incidents for this application';

    protected static ?string $description = 'Warning and danger transitions from this application\'s websites, API monitors and components in the last 7 days.';

    protected static ?int $sort = 2;

    public ?Project $record = null;

    public function mount(): void
    {
        $this->projectId = $this->record?->getKey();
    }
}
