<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Subscription\ReviewPaymentRequest;
use App\Http\Requests\SuperAdmin\ApplyRolePermissionsRequest;
use App\Http\Requests\SuperAdmin\ListBusinessesRequest;
use App\Http\Requests\SuperAdmin\ListTransactionsRequest;
use App\Http\Requests\SuperAdmin\ListUsersRequest;
use App\Http\Requests\SuperAdmin\StoreManualTransactionRequest;
use App\Http\Requests\SuperAdmin\StoreOwnerRequest;
use App\Http\Requests\SuperAdmin\UpdateBusinessRequest;
use App\Http\Requests\SuperAdmin\UpdateUserPermissionsRequest;
use App\Http\Requests\SuperAdmin\UpdateUserRequest;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PlanTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Subscription\PlanTransactionService;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminController extends ApiController
{
    /** Return platform-level totals after synchronizing expired plans. */
    public function dashboard(Request $request)
    {
        Business::syncEndedPlanStatuses();

        return $this->ok([
            'businesses' => Business::count(),
            'active_businesses' => Business::where('subscription_status', 'active')->count(),
            'trial_businesses' => Business::where('subscription_plan', 'trial')->count(),
            'users' => User::whereNotNull('business_id')->count(),
            'vehicles' => Vehicle::withoutGlobalScopes()->count(),
        ]);
    }

    /** Return a searchable page of tenant businesses and their usage totals. */
    public function businesses(ListBusinessesRequest $request)
    {
        Business::syncEndedPlanStatuses();
        $query = Business::query()->with(['users' => fn ($query) => $query->where('role', 'owner')->select('id', 'business_id', 'name', 'email')])->withCount(['users', 'vehicles']);
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($queryBuilder) => $queryBuilder->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    /** Create a tenant business and its first owner in one transaction. */
    public function storeOwner(StoreOwnerRequest $request)
    {
        $data = $request->validated();

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

    /** Update plan and identity fields controlled by the platform administrator. */
    public function updateBusiness(UpdateBusinessRequest $request, Business $business)
    {
        $data = $request->validated();
        if (array_key_exists('plan_ends_at', $data)) {
            $data['subscription_status'] = Business::statusForPlanEnd($data['plan_ends_at']);
        }
        $business->update($data);
        if (($data['status'] ?? null) === 'inactive') {
            $business->users()->each(fn (User $user) => $user->tokens()->delete());
        }

        return $this->ok($business->fresh(), 'Business updated.');
    }

    /** Return a searchable page of recorded plan purchases and renewals. */
    public function transactions(ListTransactionsRequest $request, PlanTransactionService $transactions)
    {
        $transactions->expireOldUnpaidTransactions();

        $query = PlanTransaction::query()->with(['business:id,name,email', 'creator:id,name', 'selectedPaymentMethod:id,name,account_name,account_number']);

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($transactionQuery) => $transactionQuery
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('plan', 'like', "%{$search}%")
                ->orWhereHas('business', fn ($businessQuery) => $businessQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")));
        }

        if ($request->filled('plan')) {
            $query->where('plan', $request->string('plan')->trim()->value());
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->string('payment_status')->trim()->value());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->trim()->value());
        }

        if ($request->filled('payment_method')) {
            $paymentMethod = $request->string('payment_method')->trim()->value();
            $query->where(fn ($transactionQuery) => $transactionQuery
                ->where('payment_method', $paymentMethod)
                ->orWhereHas('selectedPaymentMethod', fn ($methodQuery) => $methodQuery->where('name', $paymentMethod)));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return $this->paginated($query->latest('paid_at')->latest('id')->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    /** Record a plan purchase and activate the purchased period atomically. */
    public function storeTransaction(StoreManualTransactionRequest $request)
    {
        $data = $request->validated();

        $transaction = DB::transaction(function () use ($data, $request) {
            $business = Business::findOrFail($data['business_id']);
            $paymentMethod = PaymentMethod::findOrFail($data['payment_method_id']);
            $reference = $this->transactionReference($business, $data['paid_at']);
            $transaction = PlanTransaction::create([
                ...$data,
                'created_by' => $request->user()->id,
                'currency' => 'PHP',
                'payment_method' => $paymentMethod->name,
                'reference' => $reference,
                'status' => 'processing',
            ]);

            return $transaction;
        });

        return $this->ok($transaction->load(['business:id,name,email', 'creator:id,name', 'selectedPaymentMethod:id,name,account_name,account_number']), 'Plan transaction created.', 201);
    }

    /** Generate a readable unique reference from business, date, and random code. */
    private function transactionReference(Business $business, string $paidAt): string
    {
        $prefix = Str::upper(Str::slug($business->name, '-')) ?: 'BUSINESS';
        $date = date('Ymd', strtotime($paidAt));
        do {
            $reference = "{$prefix}-{$date}-".Str::upper(Str::random(8));
        } while (PlanTransaction::where('reference', $reference)->exists());

        return $reference;
    }

    /** Return owner rows with nested staff for the grouped user table. */
    public function users(ListUsersRequest $request)
    {
        $query = User::withTrashed()
            ->with([
                'business:id,name,subscription_plan,subscription_status,vehicle_limit_override,plan_ends_at',
                'business.users' => fn ($query) => $query->withTrashed()
                    ->where('role', 'staff')
                    ->orderBy('name'),
            ])
            ->whereNotNull('business_id')
            ->where('role', 'owner');
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($queryBuilder) => $queryBuilder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('business', fn ($businessQuery) => $businessQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('users', fn ($userQuery) => $userQuery
                        ->where('role', 'staff')
                        ->where(fn ($staffQuery) => $staffQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")))));
        }
        if ($request->filled('business_id')) {
            $query->where('business_id', $request->integer('business_id'));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    /** Update a tenant user and revoke sessions when the account is disabled. */
    public function updateUser(UpdateUserRequest $request, User $user)
    {
        abort_if($user->isSuperAdmin(), 403);
        $data = $request->validated();
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

    /** Return the permission matrix definition consumed by the frontend editor. */
    public function permissions(Request $request)
    {
        return $this->ok([
            'modules' => PermissionCatalog::MODULES,
        ]);
    }

    /** Search tenant users that can receive direct permission assignments. */
    public function permissionUsers(Request $request)
    {
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

    /** Return one tenant user's direct permission assignment. */
    public function permissionUser(Request $request, User $user)
    {
        abort_if($user->isSuperAdmin() || ! $user->business_id, 404);

        return $this->ok($this->permissionUserData($user->load('business:id,name')));
    }

    /** Replace the selected user's direct permissions. */
    public function updateUserPermissions(UpdateUserPermissionsRequest $request, User $user)
    {
        abort_if($user->isSuperAdmin() || ! $user->business_id, 403);
        $data = $request->validated();
        $user->syncPermissions(Permission::whereIn('name', $data['permissions'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->ok([
            'id' => $user->id,
            'permissions' => $user->getDirectPermissions()->pluck('name')->values(),
        ], 'User permissions updated.');
    }

    /** Replace direct permissions for every tenant user with the selected role. */
    public function applyPermissionsToRole(ApplyRolePermissionsRequest $request)
    {
        $data = $request->validated();
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

    /** Issue a short-lived user token for Super Admin dashboard impersonation. */
    public function impersonate(Request $request, User $user)
    {
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

    // Return the private payment proof uploaded by the owner.
    public function paymentProof(PlanTransaction $planTransaction)
    {
        $path = $planTransaction->payment_proof_path;

        abort_unless($path, 404, 'Payment proof not found.');
        abort_unless(
            Storage::disk('local')->exists($path),
            404,
            'Payment proof file not found.'
        );

        return Storage::disk('local')->response(
            $path,
            basename($path)
        );
    }

    // Approve or reject a payment submitted by the owner.
    public function reviewPayment(
        ReviewPaymentRequest $request,
        PlanTransaction $planTransaction,
        PlanTransactionService $transactions
    ) {
        $validated = $request->validated();

        if ($planTransaction->payment_status !== 'pending_verification') {
            return response()->json([
                'success' => false,
                'message' => 'Only payments pending verification can be reviewed.',
            ], 422);
        }

        $planTransaction = $validated['action'] === 'approve'
            ? $transactions->approvePayment($planTransaction)
            : $transactions->rejectPayment($planTransaction, $validated['rejection_reason']);

        $planTransaction = $planTransaction->fresh()->load([
            'business:id,name,email',
            'creator:id,name',
            'selectedPaymentMethod:id,name,account_name,account_number',
        ]);

        return $this->ok(
            $planTransaction,
            $validated['action'] === 'approve'
                ? 'Payment approved and subscription activated successfully.'
                : 'Payment rejected successfully.'
        );
    }

    /** Normalize the user payload used by permission search and detail endpoints. */
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
