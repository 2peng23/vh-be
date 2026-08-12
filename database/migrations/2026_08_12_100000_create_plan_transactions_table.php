<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the immutable ledger of tenant plan purchases and renewals. */
    public function up(): void
    {
        Schema::create('plan_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan', 30);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('payment_method', 50);
            $table->string('reference')->nullable()->unique();
            $table->date('paid_at');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'paid_at']);
        });
    }

    /** Remove the plan transaction ledger. */
    public function down(): void
    {
        Schema::dropIfExists('plan_transactions');
    }
};
