<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class VerifySuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:verify-superadmin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify superadmin user and permissions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', 'superadmin@checkybot.com')->first();

        if (! $user) {
            $this->error('Super Admin user not found!');

            return Command::FAILURE;
        }

        $this->info('✅ Super Admin User Found:');
        $this->info("  Name: {$user->name}");
        $this->info("  Email: {$user->email}");
        $this->info('  Roles: ' . $user->getRoleNames()->implode(', '));
        $this->info('  Total Permissions: ' . $user->getAllPermissions()->count());

        // Check key permissions
        $keyPermissions = [
            'view_any_website',
            'create_website',
            'view_any_seo_check',
            'create_seo_check',
            'view_any_user',
            'create_user',
            'view_any_role',
            'create_role',
        ];

        $this->info("\n🔑 Key Permissions Check:");
        foreach ($keyPermissions as $permission) {
            $hasPermission = $user->can($permission);
            $status = $hasPermission ? '✅' : '❌';
            $this->info("  {$status} {$permission}");
        }

        $this->info("\n🚀 Login Details:");
        $this->info('  URL: http://localhost/admin');
        $this->info('  Email: superadmin@checkybot.com');
        $this->info('  Password: password');

        return Command::SUCCESS;
    }
}
