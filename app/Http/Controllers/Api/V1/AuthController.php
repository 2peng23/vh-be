<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request)
    {
        $registration = $this->authService->register($request->validated());
        event(new Registered($registration['user']));

        return $this->ok([
            'user' => $this->withAuthorization($registration['user']),
            'business' => $registration['business'],
            'token' => $registration['token'],
        ], 'Business registered.', 201);
    }

    public function login(LoginRequest $request)
    {
        $login = $this->authService->attemptLogin($request->validated());

        if ($login['status'] === 'invalid') {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 422);
        }

        if ($login['status'] === 'restricted') {
            $message = match ($login['code']) {
                'STAFF_INACTIVE' => 'Your staff account is inactive. Please contact your business owner.',
                'BUSINESS_INACTIVE' => 'This business account has been disabled. Please contact support for assistance.',
                default => 'Your plan has ended. Please contact support to reactivate your account.',
            };

            return response()->json([
                'success' => false,
                'code' => $login['code'],
                'message' => $message,
                'data' => [
                    'user' => $this->withAuthorization($login['user']),
                    'token' => $login['token'],
                ],
            ], 403);
        }

        return $this->ok([
            'user' => $this->withAuthorization($login['user']),
            'token' => $login['token'],
        ], 'Logged in.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logged out.');
    }

    public function me(Request $request)
    {
        return $this->ok($this->withAuthorization($request->user()));
    }

    /** Return current account access state even when the tenant is restricted. */
    public function accessStatus(Request $request)
    {
        $user = $request->user();
        $user->business?->syncPlanStatus();

        return $this->ok($this->withAuthorization($user->fresh()));
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $this->authService->updateProfile($request->user(), $request->validated());

        return $this->ok($user, 'Profile updated.');
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $this->authService->changePassword($request->user(), $request->validated());

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
