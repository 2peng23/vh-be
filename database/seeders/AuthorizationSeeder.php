<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (array_merge(['super_admin'], PermissionCatalog::TENANT_ROLES) as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            if ($roleName !== 'super_admin' && $role->permissions()->doesntExist()) {
                $role->syncPermissions(PermissionCatalog::defaults($roleName));
            }
        }
        User::withTrashed()->get()->each(function (User $user) {
            $user->syncRoles($user->role->value);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
