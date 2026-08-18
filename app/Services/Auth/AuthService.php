<?php

namespace App\Services\Auth;

use App\Models\Business;
use App\Models\User;
use App\Support\SubscriptionPlans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    public function register(array $validated): array
    {
        return DB::transaction(function () use ($validated) {
            $business = $this->createBusiness($validated);
            $owner = $business->users()->create([
                'name' => $validated['owner_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'role' => 'owner',
            ]);
            $owner->syncAuthorizationRole();

            return [
                'business' => $business,
                'user' => $owner,
                'token' => $owner->createToken('registration')->plainTextToken,
            ];
        });
    }

    public function attemptLogin(array $validated): array
    {
        $user = User::where('email', $validated['email'])->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return ['status' => 'invalid'];
        }

        if ($user->role->value === 'staff' && $user->status !== 'active') {
            return $this->restrictedLogin($user, 'STAFF_INACTIVE', 'access-status');
        }

        if ($user->status !== 'active') {
            return ['status' => 'invalid'];
        }

        $user->business?->syncPlanStatus();
        if ($user->business?->isInactive()) {
            return $this->restrictedLogin($user, 'BUSINESS_INACTIVE', 'support-access');
        }

        if ($user->business?->planHasEnded()) {
            return $this->restrictedLogin($user, 'PLAN_ENDED', 'support-access');
        }

        return [
            'status' => 'ok',
            'user' => $user,
            'token' => $user->createToken($validated['device_name'] ?? 'api')->plainTextToken,
        ];
    }

    public function updateProfile(User $user, array $validated): User
    {
        $user->update($validated);

        return $user->fresh();
    }

    public function changePassword(User $user, array $validated): void
    {
        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();
    }

    private function createBusiness(array $validated): Business
    {
        $startsAt = now();

        return Business::create([
            'name' => $validated['business_name'],
            'slug' => $this->uniqueBusinessSlug($validated['business_name']),
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'industry' => $validated['industry'] ?? null,
            'plan_started_at' => $startsAt,
            'plan_ends_at' => SubscriptionPlans::trialEndsAt($startsAt),
        ]);
    }

    private function uniqueBusinessSlug(string $businessName): string
    {
        $baseSlug = Str::slug($businessName) ?: 'business';
        $slug = $baseSlug;

        while (Business::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    private function restrictedLogin(User $user, string $code, string $tokenName): array
    {
        return [
            'status' => 'restricted',
            'code' => $code,
            'user' => $user,
            'token' => $user->createToken($tokenName)->plainTextToken,
        ];
    }
}
