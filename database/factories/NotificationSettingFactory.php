<?php

namespace Database\Factories;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Models\MonitorApis;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\ProjectComponent;
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
            'monitor_api_id' => null,
            'project_component_id' => null,
            'scope' => NotificationScopesEnum::GLOBAL,
            'inspection' => WebsiteServicesEnum::ALL_CHECK,
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
            'monitor_api_id' => null,
            'project_component_id' => null,
        ]);
    }

    public function websiteScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => NotificationScopesEnum::WEBSITE,
            'website_id' => Website::factory(),
            'monitor_api_id' => null,
            'project_component_id' => null,
        ]);
    }

    public function apiMonitorScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => NotificationScopesEnum::API_MONITOR,
            'website_id' => null,
            'monitor_api_id' => MonitorApis::factory(),
            'project_component_id' => null,
        ]);
    }

    public function projectComponentScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => NotificationScopesEnum::PROJECT_COMPONENT,
            'website_id' => null,
            'monitor_api_id' => null,
            'project_component_id' => ProjectComponent::factory(),
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

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'flag_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'flag_active' => false,
        ]);
    }
}
