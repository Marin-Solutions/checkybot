<?php

    namespace App\Filament\Resources\ProjectsResource\Pages;

    use App\Filament\Resources\ProjectsResource;
    use Filament\Resources\Pages\ViewRecord;
    use Illuminate\Contracts\Support\Htmlable;
    use Illuminate\Database\Eloquent\Model;

    class ViewProjects extends ViewRecord
    {
        protected static string $resource = ProjectsResource::class;

        protected static string $view = 'filament.resources.projects-resource.pages.view-projects';

        protected ?Model $cachedRecord = null;

        /**
         * @return string|Htmlable
         */
        public function getHeading(): string|Htmlable
        {
            return $this->record->name;
        }

        public function getSubheading(): string|Htmlable|null
        {
            return ucfirst($this->record->technology) . " - " . ucfirst($this->record->environment);
        }

        public function getRecord(): Model
        {
            if ($this->cachedRecord) {
                return $this->cachedRecord;
            }

            return $this->cachedRecord = parent::getRecord()->loadCount('errorReported');
        }
    }
