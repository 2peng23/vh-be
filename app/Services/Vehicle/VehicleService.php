<?php

namespace App\Services\Vehicle;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditService;
use App\Support\SubscriptionPlans;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class VehicleService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Vehicle::query();
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(fn ($queryBuilder) => $queryBuilder
                ->where('plate_number', 'like', "%{$search}%")
                ->orWhere('vehicle_code', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%"));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sortColumn = $filters['sort'] ?? 'created_at';
        $sortDirection = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortColumn, $sortDirection)
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(User $user, array $validated): Vehicle
    {
        $business = $user->business;
        $vehicleLimit = SubscriptionPlans::vehicleLimit($business);
        $vehicleCount = $business->vehicles()->count();

        if ($vehicleCount >= $vehicleLimit) {
            $planTier = SubscriptionPlans::tier($business);

            throw ValidationException::withMessages([
                'vehicle' => "Your current {$planTier} plan allows up to {$vehicleLimit} vehicles. You currently have {$vehicleCount} vehicles. Existing vehicles will remain available, but you cannot add new vehicles until your usage is below the plan limit or you upgrade your subscription.",
            ]);
        }

        $validated['vehicle_code'] = $this->generateVehicleCode($user->business_id, $validated['vehicle_type']);
        $vehicle = Vehicle::create($validated);
        $this->auditService->record('vehicle.created', $vehicle);

        return $vehicle;
    }

    public function update(Vehicle $vehicle, array $validated): Vehicle
    {
        $oldValues = $vehicle->toArray();
        $vehicle->update($validated);
        $this->auditService->record('vehicle.updated', $vehicle, $oldValues);

        return $vehicle->fresh();
    }

    public function delete(Vehicle $vehicle): void
    {
        $vehicle->delete();
        $this->auditService->record('vehicle.deleted', $vehicle);
    }

    private function generateVehicleCode(int $businessId, string $vehicleType): string
    {
        $prefix = match (strtolower(trim($vehicleType))) {
            'truck' => 'TRK-',
            'van' => 'VAN-',
            'car' => 'CAR-',
            'bus' => 'BUS-',
            'pickup' => 'PUP-',
            'motorcycle' => 'MC-',
            'suv' => 'SUV-',
            'heavy equipment' => 'HEQ-',
            default => 'OTH-',
        };
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        do {
            $suffix = '';
            for ($index = 0; $index < 6; $index++) {
                $suffix .= $characters[random_int(0, strlen($characters) - 1)];
            }
            $vehicleCode = $prefix.$suffix;
        } while (Vehicle::withTrashed()->where('business_id', $businessId)->where('vehicle_code', $vehicleCode)->exists());

        return $vehicleCode;
    }
}
