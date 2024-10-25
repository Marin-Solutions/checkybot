<?php

    namespace Database\Seeders;

    use Illuminate\Database\Console\Seeds\WithoutModelEvents;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;

    class BackupRemoteStorageTypeOptionsSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         */
        public function run(): void
        {
            DB::table('backup_remote_storage_types')->insert([
                [
                    'name' => 'SFTP',
                    'driver' => 'sftp',
                    'flag_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'FTP',
                    'driver' => 'ftp',
                    'flag_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'AWS S3',
                    'driver' => 's3',
                    'flag_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Custom S3',
                    'driver' => 's3',
                    'flag_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);
        }
    }
