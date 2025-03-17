<?php

    namespace Database\Seeders;

    use App\Models\BackupIntervalOption;
    use Illuminate\Database\Console\Seeds\WithoutModelEvents;
    use Illuminate\Database\Seeder;

    class BackupIntervalOptionSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         */
        public function run(): void
        {
            $data = [
                ['value' => 1, 'unit' => 'hourly', 'expression' => '0 * * * *'],       // hourly
                ['value' => 6, 'unit' => 'hourly', 'expression' => '0 */6 * * *'],     // 6-hourly
                ['value' => 12, 'unit' => 'hourly', 'expression' => '0 */12 * * *'],   // 12-hourly
                ['value' => 1, 'unit' => 'daily', 'expression' => '0 0 * * *'],        // daily
                ['value' => 1, 'unit' => 'weekly', 'expression' => '0 0 * * 0'],       // weekly
                ['value' => 1, 'unit' => 'monthly', 'expression' => '0 0 1 * *'],      // monthly
            ];

            foreach ($data as $item) {
                BackupIntervalOption::create($item);
            }
        }
    }
