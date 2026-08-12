<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenantRoleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['owner', 'staff'])
            ->pluck('id');

        User::withTrashed()->whereNotNull('business_id')->each(function (User $user) use ($tenantRoleIds) {
            $permissionIds = DB::table('role_has_permissions')
                ->join('model_has_roles', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.model_id', $user->id)
                ->whereIn('model_has_roles.role_id', $tenantRoleIds)
                ->pluck('role_has_permissions.permission_id');

            foreach ($permissionIds as $permissionId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
        });

        DB::table('role_has_permissions')->whereIn('role_id', $tenantRoleIds)->delete();
    }

    public function down(): void
    {
        // Individual permission sets cannot be safely collapsed back into shared roles.
    }
};
