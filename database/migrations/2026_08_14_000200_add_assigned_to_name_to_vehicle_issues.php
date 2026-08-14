<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_issues', function (Blueprint $table) {
            $table->string('assigned_to_name', 150)->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_issues', function (Blueprint $table) {
            $table->dropColumn('assigned_to_name');
        });
    }
};
