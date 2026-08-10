<?php

namespace App\Services;

use App\Models\MileageLog;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MileageService
{
    public function record(Vehicle $vehicle, array $data): MileageLog
    {
        return DB::transaction(function () use ($vehicle, $data) {
            $override = (bool) ($data['override'] ?? false);
            if ($data['mileage'] < $vehicle->current_mileage && ! ($override && auth()->user()->can('mileage.override'))) {
                throw ValidationException::withMessages(['mileage' => 'Mileage cannot be lower than the current reading.']);
            }$log = MileageLog::create(['vehicle_id' => $vehicle->id, 'recorded_by' => auth()->id(), 'mileage' => $data['mileage'], 'recorded_at' => $data['recorded_at'] ?? now(), 'notes' => $data['notes'] ?? null, 'photo' => $data['photo'] ?? null, 'is_override' => $override]);
            $old = $vehicle->current_mileage;
            $vehicle->update(['current_mileage' => $data['mileage']]);
            if ($override) {
                app(AuditService::class)->record('mileage.override', $log, ['mileage' => $old]);
            } else {
                app(AuditService::class)->record('mileage.recorded', $log);
            }

            return $log;
        });
    }
}
