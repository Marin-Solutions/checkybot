<?php

namespace Database\Factories;

use App\Models\PloiAccounts;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PloiWebsites>
 */
class PloiWebsitesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ploi_account_id' => PloiAccounts::factory(),
            'created_by' => User::factory(),
            'site_id' => $this->faker->unique()->randomNumber(5),
            'status' => $this->faker->randomElement(['active', 'deploying', 'error']),
            'server_id' => $this->faker->randomNumber(5),
            'domain' => $this->faker->domainName(),
            'deploy_script' => $this->faker->optional()->text(),
            'web_directory' => $this->faker->optional()->randomElement(['/public', '/public_html', '/web']),
            'project_type' => $this->faker->randomElement(['laravel', 'wordpress', 'static']),
            'project_root' => $this->faker->optional()->randomElement(['/', '/app', '/src']),
            'last_deploy_at' => $this->faker->optional()->dateTimeBetween('-30 days', 'now'),
            'system_user' => $this->faker->userName(),
            'php_version' => $this->faker->randomElement(['8.1', '8.2', '8.3']),
            'health_url' => $this->faker->optional()->url(),
            'notification_urls' => $this->faker->optional()->randomElement([
                [],
                [$this->faker->url()],
                [$this->faker->url(), $this->faker->url()],
            ]),
            'has_repository' => $this->faker->boolean(70),
            'site_created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
