<?php

namespace App\Filament\Resources\Support;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

trait ValidatesProjectAssignment
{
    protected function validateProjectAssignment(mixed $projectId): void
    {
        if (blank($projectId)) {
            return;
        }

        $ownsProject = Project::query()
            ->whereKey($projectId)
            ->where('created_by', auth()->id())
            ->exists();

        if (! $ownsProject) {
            throw ValidationException::withMessages([
                'project_id' => ['Choose one of your applications.'],
            ]);
        }
    }
}
