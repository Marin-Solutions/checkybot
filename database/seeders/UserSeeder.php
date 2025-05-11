<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $usersData = [
            [
                'name' => 'Super Admin',
                'email' => 'superadmin@checkybot.com',
                'password' => 'password',
                'role' => 'Super Admin',
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@checkybot.com',
                'password' => 'password',
                'role' => 'Admin',
            ],
        ];

        foreach ($usersData as $userData) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
            ]);
            $user->assignRole($userData['role']);
        }
    }
}
