<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add the customer-declared payment state independently of administrative approval. */
    public function up(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('not_paid')->after('status');
            $table->timestamp('paid_marked_at')->nullable()->after('payment_status');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('account_name', 150)->nullable()->after('name');
        });
    }

    /** Remove customer payment tracking and payment account ownership. */
    public function down(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'paid_marked_at']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('account_name');
        });
    }
};
