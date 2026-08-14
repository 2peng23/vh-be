<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->foreignId('fuel_log_id')->nullable()->after('maintenance_record_id')->constrained()->nullOnDelete();
            $table->boolean('is_generated')->default(false)->after('fuel_log_id');
            $table->unique('fuel_log_id');
        });

        $now = now();
        DB::table('maintenance_records')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('vehicle_expenses')
                ->whereColumn('vehicle_expenses.maintenance_record_id', 'maintenance_records.id'))
            ->orderBy('id')
            ->each(function ($record) use ($now) {
                $recordedBy = $record->performed_by ?: DB::table('users')
                    ->where('business_id', $record->business_id)
                    ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
                    ->value('id');
                if (! $recordedBy) {
                    return;
                }
                DB::table('vehicle_expenses')->insert([
                    'business_id' => $record->business_id,
                    'vehicle_id' => $record->vehicle_id,
                    'category' => 'Maintenance',
                    'amount' => $record->total_cost,
                    'expense_date' => $record->service_date,
                    'vendor' => $record->service_provider,
                    'description' => "Maintenance record #{$record->id}: {$record->maintenance_type}",
                    'recorded_by' => $recordedBy,
                    'maintenance_record_id' => $record->id,
                    'is_generated' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        DB::table('fuel_logs')
            ->orderBy('id')
            ->each(function ($log) use ($now) {
                DB::table('vehicle_expenses')->insert([
                    'business_id' => $log->business_id,
                    'vehicle_id' => $log->vehicle_id,
                    'category' => 'Fuel',
                    'amount' => $log->total_amount,
                    'expense_date' => $log->fuel_date,
                    'vendor' => $log->station,
                    'description' => "Fuel log #{$log->id}",
                    'recorded_by' => $log->recorded_by,
                    'fuel_log_id' => $log->id,
                    'is_generated' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('vehicle_expenses')->where('is_generated', true)->delete();

        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->dropUnique(['fuel_log_id']);
            $table->dropConstrainedForeignId('fuel_log_id');
            $table->dropColumn('is_generated');
        });
    }
};
