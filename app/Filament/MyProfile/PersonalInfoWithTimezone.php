<?php

namespace App\Filament\MyProfile;

use App\Support\UserTimezone;
use Filament\Forms\Components\Select;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;

/**
 * Extends Breezy's PersonalInfo card to include a timezone preference.
 *
 * The timezone is rendered as a searchable Select pre-loaded with PHP's known
 * identifiers so users can pick something like "Europe/Berlin" and have all
 * Filament TextColumn `dateTime`/`since` cells rendered in their local zone.
 */
class PersonalInfoWithTimezone extends PersonalInfo
{
    public array $only = ['name', 'email', 'timezone'];

    protected function getProfileFormComponents(): array
    {
        return [
            $this->getNameComponent(),
            $this->getEmailComponent(),
            $this->getTimezoneComponent(),
        ];
    }

    protected function getTimezoneComponent(): Select
    {
        return Select::make('timezone')
            ->label(__('Timezone'))
            ->helperText(__('Dates and times across Checkybot will be displayed in this timezone. Defaults to the application timezone when empty.'))
            ->options(UserTimezone::options())
            ->searchable()
            ->native(false)
            ->placeholder(__('Application default (:timezone)', [
                'timezone' => config('app.timezone', 'UTC'),
            ]))
            ->in(UserTimezone::identifiers());
    }
}
