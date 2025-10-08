<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CreateSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create-superadmin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create superadmin user with all permissions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = 'superadmin@checkybot.com';
        $password = 'password';

        // Check if user already exists
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            $this->info("User {$email} already exists.");

            // Ensure they have Super Admin role
            if (! $existingUser->hasRole('Super Admin')) {
                $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
                $existingUser->assignRole('Super Admin');
                $this->info('Assigned Super Admin role to existing user.');
            } else {
                $this->info('User already has Super Admin role.');
            }

            return Command::SUCCESS;
        }

        // Create the user
        $user = User::create([
            'name' => 'Super Admin',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Create or get Super Admin role
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);

        // Assign Super Admin role
        $user->assignRole('Super Admin');

        $this->info('✅ Super Admin user created successfully!');
        $this->info("Email: {$email}");
        $this->info("Password: {$password}");
        $this->info('Role: Super Admin');
        $this->info('Permissions: '.$user->getAllPermissions()->count().' permissions');

        return Command::SUCCESS;
    }
}
