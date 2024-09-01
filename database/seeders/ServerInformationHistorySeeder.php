<?php

namespace Database\Seeders;

use App\Models\ServerInformationHistory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServerInformationHistorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ServerInformationHistory::factory()
        ->count(10)
        ->create();
    }
}
