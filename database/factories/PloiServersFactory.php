<?php

namespace Database\Factories;

use App\Models\PloiAccounts;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PloiServers>
 */
class PloiServersFactory extends Factory
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
            'server_id' => $this->faker->unique()->randomNumber(5),
            'type' => $this->faker->randomElement(['vps', 'dedicated', 'cloud']),
            'name' => $this->faker->domainWord().'-server',
            'ip_address' => $this->faker->ipv4(),
            'php_version' => $this->faker->randomElement(['8.1', '8.2', '8.3']),
            'mysql_version' => $this->faker->randomElement(['8.0', '8.1', '8.4']),
            'sites_count' => $this->faker->numberBetween(0, 20),
            'status' => $this->faker->randomElement(['active', 'installing', 'error']),
            'status_id' => $this->faker->numberBetween(1, 5),
            'created_by' => User::factory(),
        ];
    }
}
