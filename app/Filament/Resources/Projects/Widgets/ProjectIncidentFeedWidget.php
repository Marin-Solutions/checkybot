<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Filament\Widgets\IncidentFeedWidget;
use App\Models\Project;

class ProjectIncidentFeedWidget extends IncidentFeedWidget
{
    protected static ?string $heading = 'Recent incidents for this application';

    protected static ?string $description = 'Warning, danger and recovery transitions from this application\'s websites and API monitors in the last 7 days.';

    protected static ?int $sort = 2;

    public ?Project $record = null;

    /**
     * Reads from the public `$record` property so the project scope is
     * preserved across every Livewire request (polling, sort, filter,
     * pagination), not just the initial mount() call.
     */
    protected function getScopedProjectId(): ?int
    {
        $key = $this->record?->getKey();

        return $key === null ? null : (int) $key;
    }

    protected function getEmptyStateDescriptionText(): string
    {
        return 'No warning, danger or recovery transitions from this application\'s websites or API monitors in the last 7 days.';
    }
}
