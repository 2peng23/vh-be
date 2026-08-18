<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Staff\ListStaffRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\User;
use App\Services\Staff\StaffService;

class StaffController extends ApiController
{
    public function __construct(private readonly StaffService $staffService) {}

    public function index(ListStaffRequest $request)
    {
        return $this->paginated($this->staffService->paginate($request->user(), $request->validated()));
    }

    public function store(StoreStaffRequest $request)
    {
        $result = $this->staffService->create($request->user(), $request->validated());

        return $this->ok(
            $result['staff'],
            $result['restored'] ? 'Staff account restored.' : 'Staff account created.',
            201
        );
    }

    public function update(UpdateStaffRequest $request, User $staff)
    {
        $this->staffBelongsToOwner($request, $staff);
        $staff = $this->staffService->update($staff, $request->validated());

        return $this->ok($staff, 'Staff account updated.');
    }

    public function destroy(ListStaffRequest $request, User $staff)
    {
        $this->staffBelongsToOwner($request, $staff);
        $this->staffService->delete($staff);

        return $this->ok(null, 'Staff account removed.');
    }

    private function staffBelongsToOwner(ListStaffRequest|UpdateStaffRequest $request, User $staff): void
    {
        abort_unless($staff->business_id === $request->user()->business_id && $staff->role->value !== 'owner', 404);
    }
}
