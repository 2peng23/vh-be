<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table
                ->text('payment_rejection_reason')
                ->nullable()
                ->after('payment_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropColumn('payment_rejection_reason');
        });
    }
};
