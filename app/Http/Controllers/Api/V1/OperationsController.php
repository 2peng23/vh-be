<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MaintenanceSchedule;
use App\Models\VehicleDocument;
use App\Models\VehicleIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OperationsController extends ApiController
{
    public function maintenance(Request $request)
    {
        $filter = $request->string('filter')->value();
        $now = now();

        $query = MaintenanceSchedule::with('vehicle')
            ->when($filter === 'overdue', fn (Builder $query) => $query->where(function (Builder $query) use ($now) {
                $query->whereDate('next_service_date', '<', $now)
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('next_service_mileage')
                            ->whereRaw('next_service_mileage < (select current_mileage from vehicles where vehicles.id = maintenance_schedules.vehicle_id)');
                    });
            }))
            ->when($filter === 'upcoming', fn (Builder $query) => $query->where(function (Builder $query) use ($now) {
                $query->whereBetween('next_service_date', [$now, $now->copy()->addDays(30)])
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('next_service_mileage')
                            ->whereRaw('next_service_mileage >= (select current_mileage from vehicles where vehicles.id = maintenance_schedules.vehicle_id)')
                            ->whereRaw('next_service_mileage <= (select current_mileage from vehicles where vehicles.id = maintenance_schedules.vehicle_id) + reminder_km');
                    });
            }));

        $paginator = $query->orderByRaw('next_service_date is null, next_service_date asc')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        $paginator->getCollection()->transform(function (MaintenanceSchedule $schedule) use ($now) {
            $mileageOverdue = $schedule->next_service_mileage !== null
                && $schedule->vehicle
                && $schedule->next_service_mileage < $schedule->vehicle->current_mileage;
            $dateOverdue = $schedule->next_service_date?->isBefore($now->copy()->startOfDay()) ?? false;
            $schedule->setAttribute('due_status', $mileageOverdue || $dateOverdue ? 'overdue' : 'upcoming');

            return $schedule;
        });

        return $this->paginated($paginator);
    }

    public function issues(Request $request)
    {
        $query = VehicleIssue::with('vehicle')
            ->when($request->input('filter', 'open') === 'open', fn (Builder $query) => $query->whereNotIn('status', ['completed', 'cancelled']))
            ->when($request->filled('priority'), fn (Builder $query) => $query->where('priority', $request->input('priority')));

        return $this->paginated($query->latest('reported_at')->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function documents(Request $request)
    {
        $filter = $request->input('filter', 'expiring');
        $now = now();

        $query = VehicleDocument::with('vehicle')
            ->when($filter === 'expiring', fn (Builder $query) => $query->whereBetween('expiration_date', [$now, $now->copy()->addDays(30)]))
            ->when($filter === 'expired', fn (Builder $query) => $query->whereDate('expiration_date', '<', $now));

        return $this->paginated($query->orderBy('expiration_date')->paginate(min((int) $request->input('per_page', 20), 100)));
    }
}
