<?php

namespace Database\Factories;

use App\Enums\InspectionTypesEnum;
use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'website_id' => null,
            'scope' => NotificationScopesEnum::GLOBAL,
            'inspection' => InspectionTypesEnum::ALL_CHECK,
            'channel_type' => NotificationChannelTypesEnum::MAIL,
            'notification_channel_id' => null,
            'address' => fake()->email(),
            'flag_active' => true,
        ];
    }

    public function globalScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => NotificationScopesEnum::GLOBAL,
            'website_id' => null,
        ]);
    }

    public function websiteScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => NotificationScopesEnum::WEBSITE,
            'website_id' => Website::factory(),
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => NotificationChannelTypesEnum::MAIL,
            'notification_channel_id' => null,
            'address' => fake()->email(),
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK,
            'notification_channel_id' => NotificationChannels::factory(),
            'address' => null,
        ]);
    }
}
