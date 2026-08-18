<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->string('transaction_type', 20)->default('purchase')->after('subscription_plan_offering_id');
            $table->string('from_plan', 30)->nullable()->after('transaction_type');
            $table->decimal('original_amount', 12, 2)->default(0)->after('duration_months');
            $table->decimal('credit_amount', 12, 2)->default(0)->after('original_amount');
        });

        Schema::create('scheduled_subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('from_plan', 30);
            $table->string('to_plan', 30);
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_subscription_changes');

        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_type',
                'from_plan',
                'original_amount',
                'credit_amount',
            ]);
        });
    }
};
