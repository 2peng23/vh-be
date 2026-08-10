<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StaffController extends ApiController
{
    private const ROLES = ['staff'];

    public function index(Request $request)
    {
        $this->tenantOnly($request);
        $query = User::query()->where('business_id', $request->user()->business_id)->where('role', '!=', 'owner');
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function store(Request $request, AuditService $audit)
    {
        $this->tenantOnly($request);
        $count = User::query()->where('business_id', $request->user()->business_id)->where('role', '!=', 'owner')->count();
        if ($count >= 3) {
            throw ValidationException::withMessages(['staff' => 'Your business can have a maximum of 3 staff accounts.']);
        }
        $data = $this->validated($request);
        $data['business_id'] = $request->user()->business_id;
        $data['role'] ??= 'staff';
        $user = User::create($data);
        $user->syncAuthorizationRole();
        $audit->record('staff.created', $user);

        return $this->ok($user, 'Staff account created.', 201);
    }

    public function update(Request $request, User $staff, AuditService $audit)
    {
        $this->tenantOnly($request);
        $this->staffBelongsToOwner($request, $staff);
        $old = $staff->toArray();
        $staff->update($this->validated($request, $staff, true));
        $staff->syncAuthorizationRole();
        $audit->record('staff.updated', $staff, $old);

        return $this->ok($staff->fresh(), 'Staff account updated.');
    }

    public function destroy(Request $request, User $staff, AuditService $audit)
    {
        $this->tenantOnly($request);
        $this->staffBelongsToOwner($request, $staff);
        $staff->tokens()->delete();
        $staff->delete();
        $audit->record('staff.deleted', $staff);

        return $this->ok(null, 'Staff account removed.');
    }

    private function validated(Request $request, ?User $user = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "$required|string|max:150",
            'email' => [$required, 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:30',
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'password' => [$required, 'confirmed', Password::defaults()],
        ]);
    }

    private function tenantOnly(Request $request): void
    {
        abort_unless($request->user()->business_id, 403);
    }

    private function staffBelongsToOwner(Request $request, User $staff): void
    {
        abort_unless($staff->business_id === $request->user()->business_id && $staff->role->value !== 'owner', 404);
    }
}
