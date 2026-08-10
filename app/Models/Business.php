<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function ($b) {
            $b->subscription_plan ??= 'starter';
            $b->subscription_status ??= 'trial';
            $b->timezone ??= 'Asia/Manila';
            $b->currency ??= 'PHP';
        });
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'trial_started_at' => 'datetime', 'trial_ends_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
