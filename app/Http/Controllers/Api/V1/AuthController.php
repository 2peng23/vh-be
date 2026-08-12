<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends ApiController
{
    public function register(Request $r)
    {
        $v = $r->validate(['business_name' => 'required|string|max:150', 'owner_name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::defaults()], 'phone' => 'nullable|string|max:30', 'industry' => 'nullable|string|max:100']);
        [$b,$u] = DB::transaction(function () use ($v) {
            $base = Str::slug($v['business_name']);
            $slug = $base;
            while (Business::where('slug', $slug)->exists()) {
                $slug = $base.'-'.Str::lower(Str::random(5));
            }$b = Business::create(['name' => $v['business_name'], 'slug' => $slug, 'email' => $v['email'], 'phone' => $v['phone'] ?? null, 'industry' => $v['industry'] ?? null, 'plan_started_at' => now(), 'plan_ends_at' => now()->addDays(30)]);
            $u = $b->users()->create(['name' => $v['owner_name'], 'email' => $v['email'], 'phone' => $v['phone'] ?? null, 'password' => $v['password'], 'role' => 'owner']);
            $u->syncAuthorizationRole();

            return [$b, $u];
        });
        event(new Registered($u));

        return $this->ok(['user' => $this->withAuthorization($u), 'business' => $b, 'token' => $u->createToken('registration')->plainTextToken], 'Business registered.', 201);
    }

    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'device_name' => 'nullable|string|max:100']);
        $u = User::where('email', $v['email'])->first();
        if (! $u || ! Hash::check($v['password'], $u->password) || $u->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 422);
        }
        $u->business?->syncPlanStatus();
        if ($u->business?->planHasEnded()) {
            return response()->json([
                'success' => false,
                'code' => 'PLAN_ENDED',
                'message' => 'Your plan has ended. Please contact support to reactivate your account.',
                'data' => [
                    'user' => $this->withAuthorization($u),
                    'token' => $u->createToken('support-access')->plainTextToken,
                ],
            ], 403);
        }

        return $this->ok(['user' => $this->withAuthorization($u), 'token' => $u->createToken($v['device_name'] ?? 'api')->plainTextToken], 'Logged in.');
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logged out.');
    }

    public function me(Request $r)
    {
        return $this->ok($this->withAuthorization($r->user()));
    }

    public function updateProfile(Request $r)
    {
        $data = $r->validate(['name' => 'sometimes|required|string|max:150', 'phone' => 'nullable|string|max:30']);
        $r->user()->update($data);

        return $this->ok($r->user(), 'Profile updated.');
    }

    public function changePassword(Request $r)
    {
        $v = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::defaults()]]);
        $r->user()->update(['password' => $v['password']]);
        $r->user()->tokens()->delete();

        return $this->ok(null, 'Password changed. Please log in again.');
    }

    private function withAuthorization(User $user): User
    {
        $user->load('business');
        $permissions = $user->getAllPermissions()->pluck('name')->values();
        $user->unsetRelation('permissions');
        $user->setAttribute('permissions', $permissions);

        return $user;
    }
}
