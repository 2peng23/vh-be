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
                ->string('payment_reference')
                ->nullable()
                ->after('payment_status');

            $table
                ->string('payment_proof_path')
                ->nullable()
                ->after('payment_reference');

            $table
                ->timestamp('payment_submitted_at')
                ->nullable()
                ->after('payment_proof_path');

            $table
                ->timestamp('payment_verified_at')
                ->nullable()
                ->after('payment_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'payment_reference',
                'payment_proof_path',
                'payment_submitted_at',
                'payment_verified_at',
            ]);
        });
    }
};
