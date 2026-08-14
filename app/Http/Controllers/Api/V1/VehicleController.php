<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\AuditService;
use App\Support\SubscriptionPlans;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleController extends ApiController
{
    public function index(Request $r)
    {
        $q = Vehicle::query();
        if ($s = $r->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('plate_number', 'like', "%$s%")->orWhere('vehicle_code', 'like', "%$s%")->orWhere('brand', 'like', "%$s%")->orWhere('model', 'like', "%$s%"));
        }if ($r->filled('status')) {
            $q->where('status', $r->status);
        }$sort = in_array($r->sort, ['plate_number', 'brand', 'model', 'year', 'current_mileage', 'created_at']) ? $r->sort : 'created_at';

        return $this->paginated($q->orderBy($sort, $r->order === 'asc' ? 'asc' : 'desc')->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function store(Request $r, AuditService $audit)
    {
        $business = $r->user()->business;
        $limit = SubscriptionPlans::vehicleLimit($business);
        if ($business->vehicles()->count() >= $limit) {
            $tier = SubscriptionPlans::tier($business);
            throw ValidationException::withMessages([
                'vehicle' => "The {$tier} plan allows up to {$limit} vehicles. Upgrade the subscription to add another vehicle.",
            ]);
        }

        $data = $this->validateData($r);
        $data['vehicle_code'] = $this->generateVehicleCode($r->user()->business_id, $data['vehicle_type']);
        $v = Vehicle::create($data);
        $audit->record('vehicle.created', $v);

        return $this->ok($v, 'Vehicle created.', 201);
    }

    public function show(Vehicle $vehicle)
    {
        $this->access($vehicle);

        return $this->ok(
            $vehicle
                ->load(['schedules', 'documents', 'assignments'])
                ->loadSum('expenses', 'amount')
        );
    }

    public function update(Request $r, Vehicle $vehicle, AuditService $audit)
    {
        $this->access($vehicle);
        $old = $vehicle->toArray();
        $vehicle->update($this->validateData($r, $vehicle));
        $audit->record('vehicle.updated', $vehicle, $old);

        return $this->ok($vehicle, 'Vehicle updated.');
    }

    public function destroy(Request $r, Vehicle $vehicle, AuditService $audit)
    {
        $this->access($vehicle);
        $vehicle->delete();
        $audit->record('vehicle.deleted', $vehicle);

        return $this->ok(null, 'Vehicle archived.');
    }

    private function access(Vehicle $v): void
    {
        // Tenant scoping and the route permission middleware authorize access.
    }

    private function validateData(Request $r, ?Vehicle $v = null): array
    {
        return $r->validate(['plate_number' => ['required', 'string', 'max:30', Rule::unique('vehicles')->where('business_id', $r->user()->business_id)->ignore($v?->id)], 'brand' => 'required|string|max:100', 'model' => 'required|string|max:100', 'variant' => 'nullable|string|max:100', 'year' => 'nullable|integer|min:1900|max:'.(date('Y') + 1), 'vehicle_type' => 'required|string|max:50', 'color' => 'nullable|string|max:50', 'vin' => 'nullable|string|max:100', 'engine_number' => 'nullable|string|max:100', 'chassis_number' => 'nullable|string|max:100', 'current_mileage' => 'sometimes|integer|min:0', 'acquisition_date' => 'nullable|date', 'acquisition_cost' => 'nullable|numeric|min:0', 'status' => ['sometimes', Rule::enum(VehicleStatus::class)], 'notes' => 'nullable|string']);
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
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $characters[random_int(0, strlen($characters) - 1)];
            }
            $code = $prefix.$suffix;
        } while (Vehicle::withTrashed()->where('business_id', $businessId)->where('vehicle_code', $code)->exists());

        return $code;
    }
}
