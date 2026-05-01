<?php

namespace App\Filament\Resources\ProjectComponents\Pages;

use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use App\Models\Project;
use App\Services\IntervalParser;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProjectComponent extends EditRecord
{
    protected static string $resource = ProjectComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateProjectOwnership($data['project_id'] ?? null);
        $this->validateReportedComponentStatus($data['current_status'] ?? null);

        $interval = IntervalParser::normalizeOrFail($data['declared_interval'] ?? null, 'declared_interval');

        $data['declared_interval'] = $interval;
        $data['interval_minutes'] = IntervalParser::toMinutes($interval);
        $data['last_reported_status'] = $data['current_status'];
        $data['archived_at'] = $data['is_archived']
            ? ($this->record->archived_at ?? now())
            : null;

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

    private function validateReportedComponentStatus(mixed $status): void
    {
        if ($status !== 'unknown' || $this->record->last_heartbeat_at === null) {
            return;
        }

        throw ValidationException::withMessages([
            'current_status' => ['Components with heartbeat data cannot be reset to awaiting data.'],
        ]);
    }
}
