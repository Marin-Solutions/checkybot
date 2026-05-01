<?php

namespace App\Filament\Resources\ProjectComponents\Pages;

use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use App\Models\Project;
use App\Services\IntervalParser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateProjectComponent extends CreateRecord
{
    protected static string $resource = ProjectComponentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateProjectOwnership($data['project_id'] ?? null);

        $interval = IntervalParser::normalizeOrFail($data['declared_interval'] ?? null, 'declared_interval');

        $data['created_by'] = auth()->id();
        $data['source'] = 'manual';
        $data['declared_interval'] = $interval;
        $data['interval_minutes'] = IntervalParser::toMinutes($interval);
        $data['current_status'] = 'unknown';
        $data['last_reported_status'] = 'unknown';
        $data['summary'] = 'Awaiting first heartbeat';
        $data['metrics'] = [];
        $data['is_stale'] = false;
        $data['stale_detected_at'] = null;
        $data['archived_at'] = $data['is_archived'] ? now() : null;

        return $data;
    }

    private function validateProjectOwnership(mixed $projectId): void
    {
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
