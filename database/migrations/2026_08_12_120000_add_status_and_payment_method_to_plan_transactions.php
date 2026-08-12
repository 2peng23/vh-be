<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add workflow status and a relation to configured payment methods. */
    public function up(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('currency')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('processing')->after('reference');
        });
    }

    /** Remove transaction workflow fields. */
    public function down(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn('status');
        });
    }
};
