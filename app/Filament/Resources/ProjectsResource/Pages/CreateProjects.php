<?php

    namespace App\Filament\Resources\ProjectsResource\Pages;

    use App\Filament\Resources\ProjectsResource;
    use Filament\Actions;
    use Filament\Resources\Pages\CreateRecord;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Str;

    class CreateProjects extends CreateRecord
    {
        protected static string $resource = ProjectsResource::class;

        protected function getRedirectUrl(): string
        {
            return $this->previousUrl ?? $this->getResource()::getUrl('index');
        }

        protected function handleRecordCreation( array $data ): Model
        {
            $data[ 'token' ]      = Str::random(40);
            $data[ 'created_by' ] = Auth::user()->id;
            $project               = static::getModel()::create($data);

            return $project;
        }
    }
