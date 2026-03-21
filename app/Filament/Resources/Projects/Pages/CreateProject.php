<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['token'] = hash('sha256', (string) Str::uuid());

        return $data;
    }
}
