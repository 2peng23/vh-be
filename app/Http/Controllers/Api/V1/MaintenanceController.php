<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use Illuminate\Http\Request;

class MaintenanceController extends ApiController
{
    public function index(Request $r, Vehicle $vehicle)
    {
        return $this->paginated(MaintenanceRecord::where('vehicle_id', $vehicle->id)
            ->with(['performer:id,name', 'parts', 'maintenanceSchedule:id,maintenance_type'])
            ->latest('service_date')
            ->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function store(Request $r, Vehicle $vehicle, MaintenanceService $s)
    {
        $d = $r->validate(['maintenance_schedule_id' => 'nullable|exists:maintenance_schedules,id', 'service_provider' => 'nullable|string|max:150', 'service_date' => 'required|date', 'mileage' => 'required|integer|min:0', 'maintenance_type' => 'required|string|max:100', 'description' => 'nullable|string', 'labor_cost' => 'nullable|numeric|min:0', 'parts_cost' => 'nullable|numeric|min:0', 'other_cost' => 'nullable|numeric|min:0', 'next_service_date' => 'nullable|date', 'next_service_mileage' => 'nullable|integer|min:0', 'status' => 'nullable|string|max:30', 'notes' => 'nullable|string', 'parts' => 'nullable|array', 'parts.*.part_name' => 'required|string', 'parts.*.part_number' => 'nullable|string', 'parts.*.quantity' => 'required|numeric|min:0.01', 'parts.*.unit_cost' => 'required|numeric|min:0', 'parts.*.supplier' => 'nullable|string']);
        $d['vehicle_id'] = $vehicle->id;

        $record = $s->create($d);
        app(AuditService::class)->record('maintenance.created', $record);

        return $this->ok($record, 'Maintenance recorded.', 201);
    }

    public function update(Request $r, Vehicle $vehicle, MaintenanceRecord $maintenanceRecord)
    {
        abort_unless($maintenanceRecord->vehicle_id === $vehicle->id, 404);

        $data = $r->validate([
            'maintenance_schedule_id' => 'sometimes|nullable|exists:maintenance_schedules,id',
            'service_provider' => 'sometimes|nullable|string|max:150',
            'service_date' => 'sometimes|required|date',
            'mileage' => 'sometimes|required|integer|min:0',
            'maintenance_type' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string',
            'labor_cost' => 'sometimes|nullable|numeric|min:0',
            'parts_cost' => 'sometimes|nullable|numeric|min:0',
            'other_cost' => 'sometimes|nullable|numeric|min:0',
            'next_service_date' => 'sometimes|nullable|date',
            'next_service_mileage' => 'sometimes|nullable|integer|min:0',
            'status' => 'sometimes|nullable|string|max:30',
            'notes' => 'sometimes|nullable|string',
        ]);

        $old = $maintenanceRecord->toArray();
        $maintenanceRecord->fill($data);
        $maintenanceRecord->forceFill([
            'total_cost' => (float) ($maintenanceRecord->labor_cost ?? 0)
                + (float) ($maintenanceRecord->parts_cost ?? 0)
                + (float) ($maintenanceRecord->other_cost ?? 0),
        ])->save();
        app(AuditService::class)->record('maintenance.updated', $maintenanceRecord, $old);

        return $this->ok($maintenanceRecord->fresh()->load('parts'), 'Maintenance updated.');
    }

    public function destroy(Request $r, Vehicle $vehicle, MaintenanceRecord $maintenanceRecord)
    {
        abort_unless($maintenanceRecord->vehicle_id === $vehicle->id, 404);
        $maintenanceRecord->delete();
        app(AuditService::class)->record('maintenance.deleted', $maintenanceRecord);

        return $this->ok(null, 'Maintenance deleted.');
    }
}
