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

    protected array $setupValidationResult = [];

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

    protected function beforeSave(): void
    {
        $this->setupValidationResult = WebsiteUrlValidator::validate(
            $this->data['url'],
            fn () => $this->halt(),
            $this->getRecord()->id,
        );
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            ...$data,
            ...($this->setupValidationResult['warning_state'] ?? []),
        ];
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
            // Parse time
            [$hours, $minutes] = explode(':', $scheduleTime);

            // Calculate next run time
            $nextRunAt = match ($scheduleFrequency) {
                'daily' => now()->addDay()->setTime((int) $hours, (int) $minutes),
                'weekly' => now()->next($scheduleDay)->setTime((int) $hours, (int) $minutes),
                default => now()->addDay()->setTime((int) $hours, (int) $minutes),
            };

            $scheduleData = [
                'frequency' => $scheduleFrequency,
                'schedule_time' => $scheduleTime.':00',
                'schedule_day' => $scheduleFrequency === 'weekly' ? $scheduleDay : null,
                'is_active' => true,
                'next_run_at' => $nextRunAt,
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
}
