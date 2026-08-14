<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('maintenance_schedules')
            ->where('maintenance_type', 'General PMS')
            ->update(['maintenance_type' => 'General Maintenance Schedule']);

        DB::table('maintenance_records')
            ->where('maintenance_type', 'General PMS')
            ->update(['maintenance_type' => 'General Maintenance Schedule']);
    }

    public function down(): void
    {
        DB::table('maintenance_schedules')
            ->where('maintenance_type', 'General Maintenance Schedule')
            ->update(['maintenance_type' => 'General PMS']);

        DB::table('maintenance_records')
            ->where('maintenance_type', 'General Maintenance Schedule')
            ->update(['maintenance_type' => 'General PMS']);
    }
};
