<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the immutable ledger of tenant plan purchases and renewals. */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 150);
            $table->string('qr_path')->nullable();
            $table->string('qr_name')->nullable();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('subscription_plan_offerings', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 30);
            $table->string('name', 100);
            $table->unsignedSmallInteger('duration_months');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('vehicle_limit');
            $table->text('details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['plan', 'duration_months']);
            $table->index(['is_active', 'plan']);
        });

        $now = now();
        DB::table('subscription_plan_offerings')->insert([
            [
                'plan' => 'trial',
                'name' => 'Free Trial',
                'duration_months' => 1,
                'price' => 0,
                'vehicle_limit' => 10,
                'details' => 'Explore Vehicle Hub before choosing a paid subscription.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'starter',
                'name' => 'Starter',
                'duration_months' => 1,
                'price' => 1499,
                'vehicle_limit' => 10,
                'details' => 'Complete vehicle management for small fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'starter',
                'name' => 'Starter',
                'duration_months' => 6,
                'price' => 7999,
                'vehicle_limit' => 10,
                'details' => 'Complete vehicle management for small fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'starter',
                'name' => 'Starter',
                'duration_months' => 12,
                'price' => 14399,
                'vehicle_limit' => 10,
                'details' => 'Complete vehicle management for small fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'business',
                'name' => 'Business',
                'duration_months' => 1,
                'price' => 2999,
                'vehicle_limit' => 30,
                'details' => 'Complete vehicle management for growing fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'business',
                'name' => 'Business',
                'duration_months' => 6,
                'price' => 15999,
                'vehicle_limit' => 30,
                'details' => 'Complete vehicle management for growing fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'business',
                'name' => 'Business',
                'duration_months' => 12,
                'price' => 28799,
                'vehicle_limit' => 30,
                'details' => 'Complete vehicle management for growing fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'enterprise',
                'name' => 'Enterprise',
                'duration_months' => 1,
                'price' => 5999,
                'vehicle_limit' => 100,
                'details' => 'Complete vehicle management for large fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'enterprise',
                'name' => 'Enterprise',
                'duration_months' => 6,
                'price' => 31999,
                'vehicle_limit' => 100,
                'details' => 'Complete vehicle management for large fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'plan' => 'enterprise',
                'name' => 'Enterprise',
                'duration_months' => 12,
                'price' => 57599,
                'vehicle_limit' => 100,
                'details' => 'Complete vehicle management for large fleets.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::create('plan_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan', 30);
            $table->string('transaction_type', 20)->default('purchase');
            $table->string('from_plan', 30)->nullable();
            $table->unsignedSmallInteger('duration_months')->default(1);
            $table->decimal('amount', 12, 2);
            $table->decimal('original_amount', 12, 2)->default(0);
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 50);
            $table->string('reference')->nullable()->unique();
            $table->string('status', 20)->default('processing');
            $table->string('payment_status', 20)->default('not_paid');
            $table->string('payment_reference')->nullable();
            $table->string('payment_proof_path')->nullable();
            $table->timestamp('payment_submitted_at')->nullable();
            $table->timestamp('payment_verified_at')->nullable();
            $table->text('payment_rejection_reason')->nullable();
            $table->timestamp('paid_marked_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'paid_at']);
            $table->index(['business_id', 'status', 'payment_status']);
            $table->index(['business_id', 'plan', 'status']);
            $table->index(['business_id', 'plan', 'transaction_type', 'payment_status', 'status'], 'plan_txn_pending_lookup');
            $table->index(['payment_status', 'status', 'created_at']);
            $table->index(['status', 'paid_at']);
            $table->index(['business_id', 'starts_at', 'ends_at']);
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
            $table->index(['business_id', 'status']);
        });
    }

    /** Remove the plan transaction ledger. */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_subscription_changes');
        Schema::dropIfExists('plan_transactions');
        Schema::dropIfExists('subscription_plan_offerings');
        Schema::dropIfExists('payment_methods');
    }
};
