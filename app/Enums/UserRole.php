<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Owner = 'owner';
    case Staff = 'staff';

    public function managesVehicles(): bool
    {
        return $this === self::Owner;
    }

    public function isPlatformAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }
}
