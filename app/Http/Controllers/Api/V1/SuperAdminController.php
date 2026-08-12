<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminController extends ApiController
{
    public function dashboard(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        Business::syncEndedPlanStatuses();

        return $this->ok([
            'businesses' => Business::count(),
            'active_businesses' => Business::where('subscription_status', 'active')->count(),
            'trial_businesses' => Business::where('subscription_plan', 'trial')->count(),
            'users' => User::whereNotNull('business_id')->count(),
            'vehicles' => Vehicle::withoutGlobalScopes()->count(),
        ]);
    }

    public function businesses(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        Business::syncEndedPlanStatuses();
        $query = Business::query()->with(['users' => fn ($q) => $q->where('role', 'owner')->select('id', 'business_id', 'name', 'email')])->withCount(['users', 'vehicles']);
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function storeOwner(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'business_name' => 'required|string|max:150',
            'owner_name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => 'nullable|string|max:30',
            'password' => ['required', 'confirmed', Password::defaults()],
            'subscription_plan' => ['required', Rule::in(['trial', 'starter', 'business', 'enterprise'])],
            'vehicle_limit_override' => 'nullable|integer|min:1|max:100000',
            'plan_ends_at' => 'required|date|after_or_equal:today',
        ]);

        [$business, $owner] = DB::transaction(function () use ($data) {
            $baseSlug = Str::slug($data['business_name']) ?: 'business';
            $slug = $baseSlug;
            while (Business::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.Str::lower(Str::random(5));
            }

            $business = Business::create([
                'name' => $data['business_name'],
                'slug' => $slug,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'subscription_plan' => $data['subscription_plan'],
                'subscription_status' => Business::statusForPlanEnd($data['plan_ends_at']),
                'vehicle_limit_override' => $data['vehicle_limit_override'] ?? null,
                'plan_started_at' => now(),
                'plan_ends_at' => $data['plan_ends_at'],
            ]);
            $owner = $business->users()->create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => 'owner',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            return [$business, $owner];
        });

        return $this->ok([
            'business' => $business->fresh(),
            'owner' => $owner->fresh()->load('business'),
        ], 'Owner account created.', 201);
    }

    public function updateBusiness(Request $request, Business $business)
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'email' => 'sometimes|required|email|max:255',
            'subscription_plan' => ['sometimes', 'required', Rule::in(['trial', 'starter', 'business', 'enterprise'])],
            'plan_ends_at' => 'sometimes|nullable|date',
            'vehicle_limit_override' => 'sometimes|nullable|integer|min:1|max:100000',
        ]);
        if (array_key_exists('plan_ends_at', $data)) {
            $data['subscription_status'] = Business::statusForPlanEnd($data['plan_ends_at']);
        }
        $business->update($data);

        return $this->ok($business->fresh(), 'Business updated.');
    }

    public function users(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $query = User::withTrashed()
            ->with([
                'business:id,name,subscription_plan,subscription_status,vehicle_limit_override,plan_ends_at',
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
            'vehicle_limit_override' => 'sometimes|nullable|integer|min:1|max:100000',
        ]);
        $vehicleLimitOverride = $data['vehicle_limit_override'] ?? null;
        $updatesVehicleLimit = array_key_exists('vehicle_limit_override', $data);
        unset($data['vehicle_limit_override']);
        $user->update($data);
        if ($updatesVehicleLimit && $user->role->value === 'owner') {
            $user->business()->update(['vehicle_limit_override' => $vehicleLimitOverride]);
        }
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
        ]);
    }

    public function permissionUsers(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $query = User::withTrashed()
            ->with(['business:id,name', 'permissions'])
            ->whereNotNull('business_id');
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($user) => $user
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('role', 'like', "%{$search}%")
                ->orWhereHas('business', fn ($business) => $business
                    ->where('name', 'like', "%{$search}%")));
        }
        $paginator = $query->orderBy('name')
            ->paginate(min((int) $request->input('per_page', 20), 50));
        $paginator->getCollection()->transform(fn (User $user) => $this->permissionUserData($user));

        return $this->paginated($paginator);
    }

    public function permissionUser(Request $request, User $user)
    {
        $this->authorizeSuperAdmin($request);
        abort_if($user->isSuperAdmin() || ! $user->business_id, 404);

        return $this->ok($this->permissionUserData($user->load('business:id,name')));
    }

    public function updateUserPermissions(Request $request, User $user)
    {
        $this->authorizeSuperAdmin($request);
        abort_if($user->isSuperAdmin() || ! $user->business_id, 403);
        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ]);
        $user->syncPermissions(Permission::whereIn('name', $data['permissions'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->ok([
            'id' => $user->id,
            'permissions' => $user->getDirectPermissions()->pluck('name')->values(),
        ], 'User permissions updated.');
    }

    public function applyPermissionsToRole(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'role' => ['required', Rule::in(PermissionCatalog::TENANT_ROLES)],
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ]);
        $permissions = Permission::whereIn('name', $data['permissions'])->get();
        $users = User::query()
            ->whereNotNull('business_id')
            ->where('role', $data['role'])
            ->get();
        DB::transaction(fn () => $users->each(
            fn (User $user) => $user->syncPermissions($permissions)
        ));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->ok([
            'role' => $data['role'],
            'updated_users' => $users->count(),
            'permissions' => $permissions->pluck('name')->values(),
        ], "Permissions applied to {$users->count()} {$data['role']} users.");
    }

    public function impersonate(Request $request, User $user)
    {
        $this->authorizeSuperAdmin($request);
        abort_if($user->isSuperAdmin() || ! $user->business_id || $user->status !== 'active', 403);
        $user->load('business');
        $permissions = $user->getAllPermissions()->pluck('name')->values();
        $user->unsetRelation('permissions');
        $user->setAttribute('permissions', $permissions);

        return $this->ok([
            'user' => $user,
            'token' => $user->createToken('Super Admin impersonation')->plainTextToken,
        ], "Viewing dashboard as {$user->name}.");
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    private function permissionUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status,
            'business' => $user->business,
            'permissions' => $user->getDirectPermissions()->pluck('name')->values(),
        ];
    }
}
