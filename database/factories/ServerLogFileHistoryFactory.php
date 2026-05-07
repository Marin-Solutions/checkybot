<?php

namespace Database\Factories;

use App\Models\ServerLogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServerLogFileHistory>
 */
class ServerLogFileHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_log_category_id' => ServerLogCategory::factory(),
            'log_file_name' => 'ServerLogFiles/'.fake()->uuid().'.log',
        ];
    }
}
