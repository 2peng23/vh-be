<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyRoles = ['fleet_admin', 'mechanic', 'driver'];
        $userIds = DB::table('users')->whereIn('role', $legacyRoles)->pluck('id');

        DB::table('users')->whereIn('role', $legacyRoles)->update(['role' => 'staff']);

        $staffRoleId = DB::table('roles')->where('name', 'staff')->where('guard_name', 'web')->value('id');
        if ($staffRoleId && $userIds->isNotEmpty()) {
            DB::table('model_has_roles')
                ->where('model_type', 'App\\Models\\User')
                ->whereIn('model_id', $userIds)
                ->delete();

            DB::table('model_has_roles')->insertOrIgnore($userIds->map(fn ($id) => [
                'role_id' => $staffRoleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $id,
            ])->all());
        }
    }

    public function down(): void
    {
        // The previous specialized role cannot be inferred after conversion.
    }
};
