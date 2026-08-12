<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('businesses', 'trial_started_at')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->renameColumn('trial_started_at', 'plan_started_at');
                $table->renameColumn('trial_ends_at', 'plan_ends_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('businesses', 'plan_started_at')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->renameColumn('plan_started_at', 'trial_started_at');
                $table->renameColumn('plan_ends_at', 'trial_ends_at');
            });
        }
    }
};
