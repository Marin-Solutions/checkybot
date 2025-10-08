<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class ShieldSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $rolesWithPermissions = '[{"name":"Super Admin","guard_name":"web","permissions":["view_role","view_any_role","create_role","update_role","delete_role","delete_any_role","view_user","view_any_user","create_user","update_user","restore_user","restore_any_user","replicate_user","reorder_user","delete_user","delete_any_user","force_delete_user","force_delete_any_user","view_api_key","view_any_api_key","create_api_key","update_api_key","delete_api_key","delete_any_api_key","view_backup","view_any_backup","create_backup","update_backup","delete_backup","delete_any_backup","view_backup_remote_storage","view_any_backup_remote_storage","create_backup_remote_storage","update_backup_remote_storage","delete_backup_remote_storage","delete_any_backup_remote_storage","view_monitor_api","view_any_monitor_api","create_monitor_api","update_monitor_api","delete_monitor_api","delete_any_monitor_api","view_notification_channel","view_any_notification_channel","create_notification_channel","update_notification_channel","delete_notification_channel","delete_any_notification_channel","view_notification_setting","view_any_notification_setting","create_notification_setting","update_notification_setting","delete_notification_setting","delete_any_notification_setting","view_ploi_account","view_any_ploi_account","create_ploi_account","update_ploi_account","delete_ploi_account","delete_any_ploi_account","view_seo_check","view_any_seo_check","create_seo_check","update_seo_check","delete_seo_check","delete_any_seo_check","view_server","view_any_server","create_server","update_server","delete_server","delete_any_server","view_website","view_any_website","create_website","update_website","delete_website","delete_any_website","view_website_seo_check","view_any_website_seo_check","create_website_seo_check","update_website_seo_check","delete_website_seo_check","delete_any_website_seo_check","page_MyProfilePage"]},{"name":"Admin","guard_name":"web","permissions":["view_website","view_any_website","view_seo_check","view_any_seo_check","view_server","view_any_server","page_MyProfilePage"]}]';
        $directPermissions = '[]';

        static::makeRolesWithPermissions($rolesWithPermissions);
        static::makeDirectPermissions($directPermissions);

        $this->command->info('Shield Seeding Completed.');
    }

    protected static function makeRolesWithPermissions(string $rolesWithPermissions): void
    {
        if (! blank($rolePlusPermissions = json_decode($rolesWithPermissions, true))) {
            /** @var Model $roleModel */
            $roleModel = Utils::getRoleModel();
            /** @var Model $permissionModel */
            $permissionModel = Utils::getPermissionModel();

            foreach ($rolePlusPermissions as $rolePlusPermission) {
                $role = $roleModel::firstOrCreate([
                    'name' => $rolePlusPermission['name'],
                    'guard_name' => $rolePlusPermission['guard_name'],
                ]);

                if (! blank($rolePlusPermission['permissions'])) {
                    $permissionModels = collect($rolePlusPermission['permissions'])
                        ->map(fn ($permission) => $permissionModel::firstOrCreate([
                            'name' => $permission,
                            'guard_name' => $rolePlusPermission['guard_name'],
                        ]))
                        ->all();

                    $role->syncPermissions($permissionModels);
                }
            }
        }
    }

    public static function makeDirectPermissions(string $directPermissions): void
    {
        if (! blank($permissions = json_decode($directPermissions, true))) {
            /** @var Model $permissionModel */
            $permissionModel = Utils::getPermissionModel();

            foreach ($permissions as $permission) {
                if ($permissionModel::whereName($permission)->doesntExist()) {
                    $permissionModel::create([
                        'name' => $permission['name'],
                        'guard_name' => $permission['guard_name'],
                    ]);
                }
            }
        }
    }
}
