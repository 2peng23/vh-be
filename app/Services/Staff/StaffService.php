<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StaffService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function paginate(User $owner, array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->where('business_id', $owner->business_id)
            ->where('role', '!=', 'owner');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($queryBuilder) => $queryBuilder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        return $query
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(User $owner, array $validated): array
    {
        $deletedStaff = User::onlyTrashed()
            ->where('business_id', $owner->business_id)
            ->where('role', 'staff')
            ->where('email', $validated['email'])
            ->first();

        $validated['business_id'] = $owner->business_id;
        $validated['role'] ??= 'staff';

        $staff = DB::transaction(function () use ($validated, $deletedStaff) {
            if ($deletedStaff) {
                $oldValues = $deletedStaff->toArray();
                $deletedStaff->restore();
                $deletedStaff->update($validated);
                $deletedStaff->syncAuthorizationRole();
                $this->auditService->record('staff.restored', $deletedStaff, $oldValues);

                return $deletedStaff->fresh();
            }

            $staff = User::create($validated);
            $staff->syncAuthorizationRole();
            $this->auditService->record('staff.created', $staff);

            return $staff;
        });

        return [
            'staff' => $staff,
            'restored' => $deletedStaff !== null,
        ];
    }

    public function update(User $staff, array $validated): User
    {
        $oldValues = $staff->toArray();
        $staff->update($validated);
        $staff->syncAuthorizationRole();
        $this->auditService->record('staff.updated', $staff, $oldValues);

        return $staff->fresh();
    }

    public function delete(User $staff): void
    {
        $staff->tokens()->delete();
        $staff->delete();
        $this->auditService->record('staff.deleted', $staff);
    }
}
