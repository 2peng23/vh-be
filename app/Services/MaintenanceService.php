<?php

namespace App\Services;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MaintenanceService
{
    public function __construct(
        private readonly ExpenseSyncService $expenses,
        private readonly AuditService $auditService
    ) {}

    public function create(array $data, int $performedBy): MaintenanceRecord
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $parts = $data['parts'] ?? [];
            unset($data['parts']);
            $data['performed_by'] = $performedBy;
            $total = $this->totalCost($data);
            $record = MaintenanceRecord::create($data);
            $record->forceFill(['total_cost' => $total])->save();
            $this->expenses->maintenance($record);
            foreach ($parts as $part) {
                $part['total_cost'] = $part['quantity'] * $part['unit_cost'];
                $created = $record->parts()->create($part);
                $created->forceFill(['total_cost' => $part['total_cost']])->save();
            }
            $this->completeSchedule($record);
            $this->auditService->record('maintenance.created', $record);

            return $record->load('parts');
        });
    }

    public function update(MaintenanceRecord $maintenanceRecord, array $validated): MaintenanceRecord
    {
        return DB::transaction(function () use ($maintenanceRecord, $validated) {
            $oldValues = $maintenanceRecord->toArray();
            $maintenanceRecord->fill($validated);
            $maintenanceRecord->forceFill(['total_cost' => $this->totalCost($maintenanceRecord->toArray())])->save();
            $this->completeSchedule($maintenanceRecord);
            $this->expenses->maintenance($maintenanceRecord);
            $this->auditService->record('maintenance.updated', $maintenanceRecord, $oldValues);

            return $maintenanceRecord->fresh()->load('parts');
        });
    }

    public function delete(MaintenanceRecord $maintenanceRecord): void
    {
        DB::transaction(function () use ($maintenanceRecord) {
            $this->expenses->deleteForMaintenance($maintenanceRecord);
            $maintenanceRecord->delete();
            $this->auditService->record('maintenance.deleted', $maintenanceRecord);
        });
    }

    public function completeSchedule(MaintenanceRecord $record): void
    {
        if (! $record->maintenance_schedule_id) {
            return;
        }

        $schedule = MaintenanceSchedule::find($record->maintenance_schedule_id);
        if (! $schedule || $schedule->vehicle_id !== $record->vehicle_id) {
            return;
        }

        $usesMileage = in_array($schedule->interval_type, ['mileage', 'both']);
        $usesDate = in_array($schedule->interval_type, ['date', 'both']);
        $schedule->update([
            'last_service_date' => Carbon::parse($record->service_date)->toDateString(),
            'last_service_mileage' => $record->mileage,
            'next_service_date' => $usesDate && $schedule->interval_months
                ? Carbon::parse($record->service_date)->addMonths($schedule->interval_months)->toDateString()
                : null,
            'next_service_mileage' => $usesMileage && $schedule->interval_km
                ? $record->mileage + $schedule->interval_km
                : null,
            'status' => 'active',
        ]);
    }

    private function totalCost(array $data): float
    {
        return (float) ($data['labor_cost'] ?? 0)
            + (float) ($data['parts_cost'] ?? 0)
            + (float) ($data['other_cost'] ?? 0);
    }
}
