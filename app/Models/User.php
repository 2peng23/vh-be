<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens,HasFactory,HasRoles,Notifiable,SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (Schema::hasTable('roles')) {
                $user->syncAuthorizationRole();
            }
        });
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'role' => UserRole::class];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isVehicleManager(): bool
    {
        return $this->role->managesVehicles();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role->isPlatformAdmin();
    }

    public function syncAuthorizationRole(): void
    {
        if (Role::where('name', $this->role->value)->where('guard_name', 'web')->exists()) {
            $this->syncRoles($this->role->value);
        }
        if (! $this->isSuperAdmin() && Schema::hasTable('permissions') && $this->permissions()->doesntExist()) {
            $defaults = PermissionCatalog::defaults($this->role->value);
            $permissions = Permission::whereIn('name', $defaults)->get();
            if ($permissions->count() === count($defaults)) {
                $this->syncPermissions($permissions);
            }
        }
    }
}
