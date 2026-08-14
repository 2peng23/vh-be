<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('maintenance_schedules')
            ->orderBy('id')
            ->chunkById(100, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    $usesMileage = in_array($schedule->interval_type, ['mileage', 'both'], true);
                    $usesDate = in_array($schedule->interval_type, ['date', 'both'], true);
                    $updates = [];

                    if ($usesMileage && $schedule->next_service_mileage === null && $schedule->interval_km) {
                        $currentMileage = DB::table('vehicles')
                            ->where('id', $schedule->vehicle_id)
                            ->value('current_mileage');
                        $updates['next_service_mileage'] =
                            (int) ($schedule->last_service_mileage ?? $currentMileage ?? 0)
                            + (int) $schedule->interval_km;
                    }

                    if ($usesDate && $schedule->next_service_date === null && $schedule->interval_months) {
                        $updates['next_service_date'] = Carbon::parse(
                            $schedule->last_service_date ?? $schedule->created_at ?? now()
                        )->addMonths((int) $schedule->interval_months)->toDateString();
                    }

                    if ($updates !== []) {
                        DB::table('maintenance_schedules')
                            ->where('id', $schedule->id)
                            ->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Existing due values cannot be distinguished safely from generated values.
    }
};
