<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

class ProjectRegistrationService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{project: Project, created: bool}
     */
    public function register(User $user, array $attributes): array
    {
        $project = $this->findGuidedProject($user, $attributes)
            ?? $this->findIdentityMatch($user, $attributes);

        if ($project instanceof Project) {
            $this->updateExistingProject($project, $attributes);

            return [
                'project' => $project->fresh(),
                'created' => false,
            ];
        }

        $project = Project::query()->firstOrCreate(
            [
                'created_by' => $user->id,
                'environment' => $attributes['environment'],
                'identity_endpoint' => $attributes['identity_endpoint'],
            ],
            [
                'name' => $attributes['name'],
                'technology' => $attributes['technology'] ?? null,
                'token' => hash('sha256', (string) Str::uuid()),
            ],
        );

        $this->updateExistingProject($project, $attributes);

        return [
            'project' => $project->fresh(),
            'created' => $project->wasRecentlyCreated,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findGuidedProject(User $user, array $attributes): ?Project
    {
        if (blank($attributes['app_id'] ?? null)) {
            return null;
        }

        $project = Project::query()
            ->whereKey($attributes['app_id'])
            ->where('created_by', $user->id)
            ->first();

        if (! $project instanceof Project) {
            return null;
        }

        if (filled($project->identity_endpoint) && $project->identity_endpoint !== $attributes['identity_endpoint']) {
            return null;
        }

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findIdentityMatch(User $user, array $attributes): ?Project
    {
        return Project::query()
            ->where('created_by', $user->id)
            ->where('environment', $attributes['environment'])
            ->where('identity_endpoint', $attributes['identity_endpoint'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateExistingProject(Project $project, array $attributes): void
    {
        $updates = [];

        if (blank($project->identity_endpoint)) {
            $updates['identity_endpoint'] = $attributes['identity_endpoint'];
        }

        if (blank($project->technology) && filled($attributes['technology'] ?? null)) {
            $updates['technology'] = $attributes['technology'];
        }

        if ($updates === []) {
            return;
        }

        $project->update($updates);
    }
}
