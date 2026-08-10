<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => config('vehiclehub.super_admin.email')],
            [
                'business_id' => null,
                'name' => config('vehiclehub.super_admin.name'),
                'password' => Hash::make(config('vehiclehub.super_admin.password')),
                'role' => 'super_admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );
        $user->syncAuthorizationRole();
    }
}
