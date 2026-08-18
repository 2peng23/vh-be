<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Maintenance\ListMaintenanceRecordsRequest;
use App\Http\Requests\Maintenance\StoreMaintenanceRequest;
use App\Http\Requests\Maintenance\UpdateMaintenanceRequest;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\MaintenanceService;

class MaintenanceController extends ApiController
{
    public function index(ListMaintenanceRecordsRequest $request, Vehicle $vehicle)
    {
        $filters = $request->validated();

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
                ->paginate(min((int) $request->input('per_page', 20), 100)),
            ['total_amount' => $totalAmount],
        );
    }

    public function store(StoreMaintenanceRequest $request, Vehicle $vehicle, MaintenanceService $maintenanceService)
    {
        $validated = $request->validated();
        $validated['vehicle_id'] = $vehicle->id;

        $record = $maintenanceService->create($validated, $request->user()->id);

        return $this->ok($record, 'Maintenance recorded.', 201);
    }

    public function update(UpdateMaintenanceRequest $request, Vehicle $vehicle, MaintenanceRecord $maintenanceRecord, MaintenanceService $maintenanceService)
    {
        abort_unless($maintenanceRecord->vehicle_id === $vehicle->id, 404);
        $maintenanceRecord = $maintenanceService->update($maintenanceRecord, $request->validated());

        return $this->ok($maintenanceRecord, 'Maintenance updated.');
    }

    public function destroy(Vehicle $vehicle, MaintenanceRecord $maintenanceRecord, MaintenanceService $maintenanceService)
    {
        abort_unless($maintenanceRecord->vehicle_id === $vehicle->id, 404);
        $maintenanceService->delete($maintenanceRecord);

        return $this->ok(null, 'Maintenance deleted.');
    }
}
