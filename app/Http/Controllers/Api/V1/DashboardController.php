<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MaintenanceSchedule;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends ApiController
{
    public function __invoke(Request $r)
    {
        $now = now();
        $month = VehicleExpense::whereBetween('expense_date', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->sum('amount');
        $year = VehicleExpense::whereYear('expense_date', $now->year)->sum('amount');

        $overdueMaintenance = MaintenanceSchedule::where(function ($query) use ($now) {
            $query
                ->whereDate('next_service_date', '<', $now)
                ->orWhere(function ($mileageQuery) {
                    $mileageQuery
                        ->whereNotNull('next_service_mileage')
                        ->whereRaw('next_service_mileage < (select current_mileage from vehicles where vehicles.id = maintenance_schedules.vehicle_id)');
                });
        })->count();
        $totalMaintenance = MaintenanceSchedule::count();
        $upcomingMaintenance = $totalMaintenance - $overdueMaintenance;

        return $this->ok(['vehicles' => ['total' => Vehicle::count(), 'active' => Vehicle::where('status', 'active')->count(), 'maintenance' => Vehicle::where('status', 'maintenance')->count()], 'assignments' => ['total' => VehicleAssignment::count(), 'active' => VehicleAssignment::where('status', 'active')->count()], 'issues' => ['open' => VehicleIssue::whereNotIn('status', ['completed', 'cancelled'])->count(), 'critical' => VehicleIssue::where('priority', 'critical')->whereNotIn('status', ['completed', 'cancelled'])->count()], 'maintenance' => ['total' => $totalMaintenance, 'upcoming' => $upcomingMaintenance, 'overdue' => $overdueMaintenance], 'documents' => ['expiring' => VehicleDocument::whereBetween('expiration_date', [$now, $now->copy()->addDays(30)])->count(), 'expired' => VehicleDocument::whereDate('expiration_date', '<', $now)->count()], 'expenses' => ['month' => (float) $month, 'year' => (float) $year, 'by_category' => VehicleExpense::select('category', DB::raw('SUM(amount) total'))->whereYear('expense_date', $now->year)->groupBy('category')->get()]]);
    }
}
