<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Models\SeoSchedule;
use App\Models\Website;
use App\Services\WebsiteUrlValidator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected ?array $setupValidationResult = null;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->setupValidationResult ??= WebsiteUrlValidator::inspect($data['url']);

        $user = Auth::user();
        $data['created_by'] = $user->id;
        $sslExpiryDate = Website::sslExpiryDate($data['url']);
        $data['ssl_expiry_date'] = $sslExpiryDate;

        return [
            ...$data,
            ...($this->setupValidationResult['warning_state'] ?? []),
        ];
    }

    protected function afterValidate(): void
    {
        $this->setupValidationResult = WebsiteUrlValidator::inspect($this->data['url']);
    }

    protected function beforeValidate(): void
    {
        WebsiteUrlValidator::flushInspectionCache();
        $this->setupValidationResult = null;
    }

    protected function beforeCreate(): void
    {
        $this->setupValidationResult = WebsiteUrlValidator::validate(
            $this->data['url'],
            fn () => $this->halt()
        );
    }

    protected function afterCreate(): void
    {
        $website = $this->getRecord();
        $scheduleEnabled = $this->data['seo_schedule_enabled'] ?? false;
        $scheduleFrequency = $this->data['seo_schedule_frequency'] ?? null;
        $scheduleTime = $this->data['seo_schedule_time'] ?? '02:00';
        $scheduleDay = $this->data['seo_schedule_day'] ?? 'Monday';

        if ($scheduleEnabled && $scheduleFrequency) {
            SeoSchedule::create([
                'website_id' => $website->id,
                'created_by' => Auth::id(),
                'frequency' => $scheduleFrequency,
                'schedule_time' => $scheduleTime.':00',
                'schedule_day' => $scheduleFrequency === 'weekly' ? $scheduleDay : null,
                'is_active' => true,
                'next_run_at' => SeoSchedule::calculateNextRunAt($scheduleFrequency, $scheduleTime, $scheduleDay),
            ]);
        }
    }
}
