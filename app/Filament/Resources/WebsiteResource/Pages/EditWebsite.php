<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Models\SeoSchedule;
use App\Services\WebsiteUrlValidator;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditWebsite extends EditRecord
{
    protected static string $resource = WebsiteResource::class;

    protected ?array $setupValidationResult = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $website = $this->getRecord();
        $schedule = $website->seoSchedule;

        if ($schedule) {
            $data['seo_schedule_enabled'] = $schedule->is_active;
            $data['seo_schedule_frequency'] = $schedule->frequency;
            $data['seo_schedule_time'] = $schedule->schedule_time ?? '02:00';
            $data['seo_schedule_day'] = $schedule->schedule_day ?? 'Monday';
        }

        return $data;
    }

    protected function beforeValidate(): void
    {
        WebsiteUrlValidator::flushInspectionCache();
        $this->setupValidationResult = null;
    }

    protected function afterValidate(): void
    {
        if (! $this->isUrlChanged()) {
            return;
        }

        $this->setupValidationResult = WebsiteUrlValidator::inspect(
            $this->data['url'],
            $this->getRecord()->id,
        );
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->isUrlChanged()) {
            return $data;
        }

        $this->setupValidationResult ??= WebsiteUrlValidator::inspect(
            $data['url'],
            $this->getRecord()->id,
        );

        return [
            ...$data,
            ...($this->setupValidationResult['warning_state'] ?? []),
        ];
    }

    protected function beforeSave(): void
    {
        if (! $this->isUrlChanged()) {
            return;
        }

        $this->setupValidationResult = WebsiteUrlValidator::validate(
            $this->data['url'],
            fn () => $this->halt(),
            $this->getRecord()->id,
        );
    }

    protected function afterSave(): void
    {
        $website = $this->getRecord();
        $scheduleEnabled = $this->data['seo_schedule_enabled'] ?? false;
        $scheduleFrequency = $this->data['seo_schedule_frequency'] ?? null;
        $scheduleTime = $this->data['seo_schedule_time'] ?? '02:00';
        $scheduleDay = $this->data['seo_schedule_day'] ?? 'Monday';

        $existingSchedule = $website->seoSchedule;

        if ($scheduleEnabled && $scheduleFrequency) {
            $scheduleData = [
                'frequency' => $scheduleFrequency,
                'schedule_time' => $scheduleTime.':00',
                'schedule_day' => $scheduleFrequency === 'weekly' ? $scheduleDay : null,
                'is_active' => true,
                'next_run_at' => SeoSchedule::calculateNextRunAt($scheduleFrequency, $scheduleTime, $scheduleDay),
            ];

            if ($existingSchedule) {
                // Update existing schedule
                $existingSchedule->update($scheduleData);
            } else {
                // Create new schedule
                SeoSchedule::create(array_merge($scheduleData, [
                    'website_id' => $website->id,
                    'created_by' => Auth::id(),
                ]));
            }
        } else {
            // Disable or delete schedule if it exists
            if ($existingSchedule) {
                $existingSchedule->update(['is_active' => false]);
            }
        }
    }

    protected function isUrlChanged(): bool
    {
        return ($this->data['url'] ?? null) !== $this->getRecord()->url;
    }
}
