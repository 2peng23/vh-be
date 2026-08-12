<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create configurable subscription offerings and link purchases to them. */
    public function up(): void
    {
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
        });

        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->foreignId('subscription_plan_offering_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('duration_months')->default(1)->after('plan');
            $table->date('starts_at')->nullable()->change();
            $table->date('ends_at')->nullable()->change();
        });

        $now = now();
        $plans = [
            ['starter', 'Starter', 5, 'Complete vehicle management for small fleets.'],
            ['business', 'Business', 25, 'Complete vehicle management for growing fleets.'],
            ['enterprise', 'Enterprise', 100, 'Complete vehicle management for large fleets.'],
        ];
        $durations = [1 => 1500, 6 => 8100, 12 => 14400];
        foreach ($plans as [$plan, $name, $limit, $details]) {
            foreach ($durations as $months => $basePrice) {
                DB::table('subscription_plan_offerings')->insert([
                    'plan' => $plan,
                    'name' => $name,
                    'duration_months' => $months,
                    'price' => $basePrice * ($plan === 'business' ? 2 : ($plan === 'enterprise' ? 4 : 1)),
                    'vehicle_limit' => $limit,
                    'details' => $details,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** Remove configurable offerings from the subscription ledger. */
    public function down(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_offering_id');
            $table->dropColumn('duration_months');
            $table->date('starts_at')->nullable(false)->change();
            $table->date('ends_at')->nullable(false)->change();
        });
        Schema::dropIfExists('subscription_plan_offerings');
    }
};
