<?php

namespace App\Services;

use App\Models\MaintenanceRecord;
use Illuminate\Support\Facades\DB;

class MaintenanceService
{
    public function create(array $data): MaintenanceRecord
    {
        return DB::transaction(function () use ($data) {
            $parts = $data['parts'] ?? [];
            unset($data['parts']);
            $data['performed_by'] = auth()->id();
            $total = ($data['labor_cost'] ?? 0) + ($data['parts_cost'] ?? 0) + ($data['other_cost'] ?? 0);
            $record = MaintenanceRecord::create($data);
            $record->forceFill(['total_cost' => $total])->save();
            foreach ($parts as $part) {
                $part['total_cost'] = $part['quantity'] * $part['unit_cost'];
                $created = $record->parts()->create($part);
                $created->forceFill(['total_cost' => $part['total_cost']])->save();
            }if ($record->maintenance_schedule_id) {
                $record->maintenanceSchedule?->update(['last_service_date' => $record->service_date, 'last_service_mileage' => $record->mileage, 'next_service_date' => $record->next_service_date, 'next_service_mileage' => $record->next_service_mileage]);
            }

            return $record->load('parts');
        });
    }
}
