<?php

namespace App\Models;

use App\Support\SubscriptionPlans;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['subscription'];

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            $business->subscription_plan ??= 'trial';
            $business->subscription_status ??= 'active';
            $business->status ??= 'active';
            $business->timezone ??= 'Asia/Manila';
            $business->currency ??= 'PHP';
        });
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'plan_started_at' => 'datetime', 'plan_ends_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function supportMessages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }

    public function planTransactions(): HasMany
    {
        return $this->hasMany(PlanTransaction::class);
    }

    public function scheduledSubscriptionChanges(): HasMany
    {
        return $this->hasMany(ScheduledSubscriptionChange::class);
    }

    public function getSubscriptionAttribute(): array
    {
        return SubscriptionPlans::summary($this);
    }

    public function planHasEnded(): bool
    {
        return $this->plan_ends_at !== null
            && $this->plan_ends_at->toDateString() < now($this->timezone ?: config('app.timezone'))->toDateString();
    }

    /** Determine whether platform access has been explicitly disabled. */
    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    public function syncPlanStatus(): void
    {
        $expectedStatus = $this->planHasEnded() ? 'past_due' : 'active';
        if ($this->subscription_status !== $expectedStatus) {
            $this->updateQuietly(['subscription_status' => $expectedStatus]);
        }
    }

    public static function syncEndedPlanStatuses(): int
    {
        $pastDue = static::query()
            ->whereNotNull('plan_ends_at')
            ->whereDate('plan_ends_at', '<', now(config('app.timezone'))->toDateString())
            ->where('subscription_status', '!=', 'past_due')
            ->update(['subscription_status' => 'past_due']);
        $active = static::query()
            ->where(fn ($query) => $query->whereNull('plan_ends_at')
                ->orWhereDate('plan_ends_at', '>=', now(config('app.timezone'))->toDateString()))
            ->where('subscription_status', '!=', 'active')
            ->update(['subscription_status' => 'active']);

        return $pastDue + $active;
    }

    public static function statusForPlanEnd(?string $planEndsAt): string
    {
        return $planEndsAt !== null
            && substr($planEndsAt, 0, 10) < now(config('app.timezone'))->toDateString()
                ? 'past_due'
                : 'active';
    }
}
