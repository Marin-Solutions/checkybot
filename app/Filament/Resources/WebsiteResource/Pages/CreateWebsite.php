<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Models\SeoSchedule;
use App\Models\Website;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->previousUrl;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $data['created_by'] = $user->id;
        $sslExpiryDate = Website::sslExpiryDate($data['url']);
        $data['ssl_expiry_date'] = $sslExpiryDate;

        return $data;
    }

    protected function beforeCreate(): void
    {
        \App\Services\WebsiteUrlValidator::validate(
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
            // Parse time
            [$hours, $minutes] = explode(':', $scheduleTime);

            // Calculate next run time
            $nextRunAt = match ($scheduleFrequency) {
                'daily' => now()->addDay()->setTime((int) $hours, (int) $minutes),
                'weekly' => now()->next($scheduleDay)->setTime((int) $hours, (int) $minutes),
                default => now()->addDay()->setTime((int) $hours, (int) $minutes),
            };

            SeoSchedule::create([
                'website_id' => $website->id,
                'created_by' => Auth::id(),
                'frequency' => $scheduleFrequency,
                'schedule_time' => $scheduleTime.':00',
                'schedule_day' => $scheduleFrequency === 'weekly' ? $scheduleDay : null,
                'is_active' => true,
                'next_run_at' => $nextRunAt,
            ]);
        }
    }
}
