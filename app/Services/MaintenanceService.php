<?php

namespace App\Services;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MaintenanceService
{
    public function __construct(private readonly ExpenseSyncService $expenses) {}

    public function create(array $data): MaintenanceRecord
    {
        return DB::transaction(function () use ($data) {
            $parts = $data['parts'] ?? [];
            unset($data['parts']);
            $data['performed_by'] = auth()->id();
            $total = ($data['labor_cost'] ?? 0) + ($data['parts_cost'] ?? 0) + ($data['other_cost'] ?? 0);
            $record = MaintenanceRecord::create($data);
            $record->forceFill(['total_cost' => $total])->save();
            $this->expenses->maintenance($record);
            foreach ($parts as $part) {
                $part['total_cost'] = $part['quantity'] * $part['unit_cost'];
                $created = $record->parts()->create($part);
                $created->forceFill(['total_cost' => $part['total_cost']])->save();
            }
            $this->completeSchedule($record);

            return $record->load('parts');
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
}
