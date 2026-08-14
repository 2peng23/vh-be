<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\AuditService;
use App\Services\ExpenseSyncService;
use App\Services\MaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaintenanceController extends ApiController
{
    public function index(Request $r, Vehicle $vehicle)
    {
        $filters = $r->validate([
            'record_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'performed_by' => 'nullable|string|max:150',
            'service_provider' => 'nullable|string|max:150',
            'maintenance_type' => 'nullable|string|max:100',
            'sort_by' => 'nullable|in:performed_by_name,service_provider,service_date,mileage,maintenance_type',
            'sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|in:10,20,50,100',
        ]);

        $sortBy = $filters['sort_by'] ?? 'service_date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query = MaintenanceRecord::query()
            ->select('maintenance_records.*')
            ->where('maintenance_records.vehicle_id', $vehicle->id)
            ->with(['performer:id,name', 'parts', 'maintenanceSchedule:id,maintenance_type'])
            ->when(isset($filters['record_id']), fn ($query) => $query->whereKey($filters['record_id']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('service_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('service_date', '<=', $filters['to']))
            ->when(isset($filters['performed_by']), fn ($query) => $query->whereHas('performer', fn ($performer) => $performer->where('name', 'like', '%'.$filters['performed_by'].'%')))
            ->when(isset($filters['service_provider']), fn ($query) => $query->where('service_provider', 'like', '%'.$filters['service_provider'].'%'))
            ->when(isset($filters['maintenance_type']), fn ($query) => $query->where('maintenance_records.maintenance_type', 'like', '%'.$filters['maintenance_type'].'%'));

        $totalAmount = (float) (clone $query)->sum('maintenance_records.total_cost');

        if ($sortBy === 'performed_by_name') {
            $query->leftJoin('users as performers', 'performers.id', '=', 'maintenance_records.performed_by')
                ->orderBy('performers.name', $sortDirection);
        } else {
            $query->orderBy('maintenance_records.'.$sortBy, $sortDirection);
        }

        return $this->paginated(
            $query->orderBy('maintenance_records.id', 'desc')
                ->paginate(min((int) $r->input('per_page', 20), 100)),
            ['total_amount' => $totalAmount],
        );
    }

    public function store(Request $r, Vehicle $vehicle, MaintenanceService $s)
    {
        $d = $r->validate(['maintenance_schedule_id' => ['nullable', Rule::exists('maintenance_schedules', 'id')->where('vehicle_id', $vehicle->id)->where('business_id', $r->user()->business_id)], 'service_provider' => 'nullable|string|max:150', 'service_date' => 'required|date', 'mileage' => 'required|integer|min:0', 'maintenance_type' => 'required|string|max:100', 'description' => 'nullable|string', 'labor_cost' => 'nullable|numeric|min:0', 'parts_cost' => 'nullable|numeric|min:0', 'other_cost' => 'nullable|numeric|min:0', 'status' => 'nullable|string|max:30', 'notes' => 'nullable|string', 'parts' => 'nullable|array', 'parts.*.part_name' => 'required|string', 'parts.*.part_number' => 'nullable|string', 'parts.*.quantity' => 'required|numeric|min:0.01', 'parts.*.unit_cost' => 'required|numeric|min:0', 'parts.*.supplier' => 'nullable|string']);
        $d['vehicle_id'] = $vehicle->id;

        $record = $s->create($d);
        app(AuditService::class)->record('maintenance.created', $record);

        return $this->ok($record, 'Maintenance recorded.', 201);
    }

    public function update(Request $r, Vehicle $vehicle, MaintenanceRecord $maintenanceRecord, ExpenseSyncService $expenses, MaintenanceService $maintenance)
    {
        abort_unless($maintenanceRecord->vehicle_id === $vehicle->id, 404);

        $data = $r->validate([
            'maintenance_schedule_id' => ['sometimes', 'nullable', Rule::exists('maintenance_schedules', 'id')->where('vehicle_id', $vehicle->id)->where('business_id', $r->user()->business_id)],
            'service_provider' => 'sometimes|nullable|string|max:150',
            'service_date' => 'sometimes|required|date',
            'mileage' => 'sometimes|required|integer|min:0',
            'maintenance_type' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string',
            'labor_cost' => 'sometimes|nullable|numeric|min:0',
            'parts_cost' => 'sometimes|nullable|numeric|min:0',
            'other_cost' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|nullable|string|max:30',
            'notes' => 'sometimes|nullable|string',
        ]);

        DB::transaction(function () use ($maintenanceRecord, $data, $expenses, $maintenance) {
            $old = $maintenanceRecord->toArray();
            $maintenanceRecord->fill($data);
            $maintenanceRecord->forceFill([
                'total_cost' => (float) ($maintenanceRecord->labor_cost ?? 0)
                    + (float) ($maintenanceRecord->parts_cost ?? 0)
                    + (float) ($maintenanceRecord->other_cost ?? 0),
            ])->save();
            $maintenance->completeSchedule($maintenanceRecord);
            $expenses->maintenance($maintenanceRecord);
            app(AuditService::class)->record('maintenance.updated', $maintenanceRecord, $old);
        });

        return $this->ok($maintenanceRecord->fresh()->load('parts'), 'Maintenance updated.');
    }

    public function destroy(Request $r, Vehicle $vehicle, MaintenanceRecord $maintenanceRecord, ExpenseSyncService $expenses)
    {
        abort_unless($maintenanceRecord->vehicle_id === $vehicle->id, 404);
        DB::transaction(function () use ($maintenanceRecord, $expenses) {
            $expenses->deleteForMaintenance($maintenanceRecord);
            $maintenanceRecord->delete();
            app(AuditService::class)->record('maintenance.deleted', $maintenanceRecord);
        });

        return $this->ok(null, 'Maintenance deleted.');
    }
}
