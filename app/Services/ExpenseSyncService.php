<?php

namespace App\Services;

use App\Models\FuelLog;
use App\Models\MaintenanceRecord;
use App\Models\VehicleExpense;

class ExpenseSyncService
{
    public function maintenance(MaintenanceRecord $record): VehicleExpense
    {
        return $this->sync(
            ['maintenance_record_id' => $record->id],
            [
                'business_id' => $record->business_id,
                'vehicle_id' => $record->vehicle_id,
                'category' => 'Maintenance',
                'amount' => $record->total_cost,
                'expense_date' => $record->service_date,
                'vendor' => $record->service_provider,
                'description' => "Maintenance record #{$record->id}: {$record->maintenance_type}",
                'recorded_by' => $record->performed_by,
                'is_generated' => true,
            ],
        );
    }

    public function fuel(FuelLog $log): VehicleExpense
    {
        return $this->sync(
            ['fuel_log_id' => $log->id],
            [
                'business_id' => $log->business_id,
                'vehicle_id' => $log->vehicle_id,
                'category' => 'Fuel',
                'amount' => $log->total_amount,
                'expense_date' => $log->fuel_date,
                'vendor' => $log->station,
                'description' => "Fuel log #{$log->id}",
                'recorded_by' => $log->recorded_by,
                'is_generated' => true,
            ],
        );
    }

    public function deleteForMaintenance(MaintenanceRecord $record): void
    {
        VehicleExpense::where('maintenance_record_id', $record->id)->delete();
    }

    public function deleteForFuel(FuelLog $log): void
    {
        VehicleExpense::where('fuel_log_id', $log->id)->delete();
    }

    private function sync(array $source, array $values): VehicleExpense
    {
        $expense = VehicleExpense::withTrashed()->firstOrNew($source);
        $expense->fill($values);
        $expense->deleted_at = null;
        $expense->save();

        return $expense;
    }
}
