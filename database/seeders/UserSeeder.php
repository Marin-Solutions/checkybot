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
                'email' => 'superadmin@nxtyou.de',
                'password' => 'gVRDLfcOLzsHhsB',
                'role' => 'Super Admin',
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@nxtyou.de',
                'password' => '63R46ovCj30W',
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
