<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('businesses')
            ->where('subscription_status', 'trial')
            ->update(['subscription_plan' => 'trial', 'subscription_status' => 'active']);

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('subscription_plan')->default('trial')->change();
            $table->string('subscription_status')->default('active')->change();
        });
    }

    public function down(): void
    {
        DB::table('businesses')
            ->where('subscription_plan', 'trial')
            ->update(['subscription_plan' => 'starter', 'subscription_status' => 'trial']);

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('subscription_plan')->default('starter')->change();
            $table->string('subscription_status')->default('trial')->change();
        });
    }
};
