<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminController extends ApiController
{
    public function dashboard(Request $request)
    {
        $this->authorizeSuperAdmin($request);

        return $this->ok([
            'businesses' => Business::count(),
            'active_businesses' => Business::where('subscription_status', 'active')->count(),
            'trial_businesses' => Business::where('subscription_status', 'trial')->count(),
            'users' => User::whereNotNull('business_id')->count(),
            'vehicles' => Vehicle::withoutGlobalScopes()->count(),
        ]);
    }

    public function businesses(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $query = Business::query()->with(['users' => fn ($q) => $q->where('role', 'owner')->select('id', 'business_id', 'name', 'email')])->withCount(['users', 'vehicles']);
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function updateBusiness(Request $request, Business $business)
    {
        $this->authorizeSuperAdmin($request);
        $business->update($request->validate([
            'name' => 'sometimes|required|string|max:150',
            'email' => 'sometimes|required|email|max:255',
            'subscription_plan' => 'sometimes|required|string|max:50',
            'subscription_status' => ['sometimes', Rule::in(['trial', 'active', 'past_due', 'suspended', 'cancelled'])],
            'trial_ends_at' => 'sometimes|nullable|date',
        ]));

        return $this->ok($business->fresh(), 'Business updated.');
    }

    public function users(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $query = User::withTrashed()
            ->with([
                'business:id,name',
                'business.users' => fn ($q) => $q->withTrashed()
                    ->where('role', 'staff')
                    ->orderBy('name'),
            ])
            ->whereNotNull('business_id')
            ->where('role', 'owner');
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('business', fn ($business) => $business
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('users', fn ($user) => $user
                        ->where('role', 'staff')
                        ->where(fn ($staff) => $staff
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")))));
        }
        if ($request->filled('business_id')) {
            $query->where('business_id', $request->integer('business_id'));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function updateUser(Request $request, User $user)
    {
        $this->authorizeSuperAdmin($request);
        abort_if($user->isSuperAdmin(), 403);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['owner', 'staff'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'password' => ['sometimes', 'confirmed', Password::defaults()],
        ]);
        $user->update($data);
        $user->syncAuthorizationRole();
        if (($data['status'] ?? null) === 'inactive') {
            $user->tokens()->delete();
        }

        return $this->ok($user->fresh()->load('business:id,name'), 'User updated.');
    }

    public function permissions(Request $request)
    {
        $this->authorizeSuperAdmin($request);

        return $this->ok([
            'modules' => PermissionCatalog::MODULES,
            'roles' => collect(PermissionCatalog::TENANT_ROLES)->map(function (string $name) {
                $role = Role::findOrCreate($name, 'web');

                return [
                    'name' => $name,
                    'permissions' => $role->permissions()->pluck('name')->values(),
                    'users_count' => User::where('role', $name)->count(),
                ];
            })->values(),
        ]);
    }

    public function updateRolePermissions(Request $request, string $role)
    {
        $this->authorizeSuperAdmin($request);
        abort_unless(in_array($role, PermissionCatalog::TENANT_ROLES, true), 404);
        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ]);
        $model = Role::findOrCreate($role, 'web');
        $model->syncPermissions(Permission::whereIn('name', $data['permissions'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->ok([
            'name' => $role,
            'permissions' => $model->permissions()->pluck('name')->values(),
        ], 'Role permissions updated.');
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }
}
