<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);
        for ($b = 1; $b <= 3; $b++) {
            $business = Business::create(['name' => "Demo Vehicle $b", 'slug' => "demo-vehicle-$b", 'email' => "vehicle$b@example.com", 'plan_started_at' => now(), 'plan_ends_at' => now()->addDays(30), 'subscription_plan' => 'business']);
            User::create(['business_id' => $business->id, 'name' => "Demo Owner $b", 'email' => "owner$b@vh.test", 'password' => Hash::make('password'), 'role' => 'owner', 'email_verified_at' => now()]);
            $staff = collect();
            for ($s = 1; $s <= 2; $s++) {
                $staffNumber = (($b - 1) * 2) + $s;
                $staff->push(User::create(['business_id' => $business->id, 'name' => "Staff $staffNumber", 'email' => "staff$staffNumber@vh.test", 'password' => Hash::make('password'), 'role' => 'staff', 'email_verified_at' => now()]));
            }
            for ($i = 1; $i <= 8; $i++) {
                $v = Vehicle::withoutGlobalScopes()->create(['business_id' => $business->id, 'plate_number' => "FD-$b".str_pad($i, 3, '0', STR_PAD_LEFT), 'vehicle_code' => "V-$i", 'brand' => fake()->randomElement(['Toyota', 'Isuzu', 'Mitsubishi', 'Honda']), 'model' => fake()->randomElement(['HiAce', 'N-Series', 'L300', 'City']), 'year' => fake()->numberBetween(2018, 2026), 'vehicle_type' => fake()->randomElement(['Van', 'Truck', 'Car']), 'current_mileage' => fake()->numberBetween(10000, 120000), 'status' => 'active']);
                MaintenanceSchedule::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'maintenance_type' => 'General PMS', 'interval_type' => 'both', 'interval_km' => 5000, 'interval_months' => 6, 'next_service_mileage' => $v->current_mileage + fake()->numberBetween(-500, 2500), 'next_service_date' => now()->addDays(fake()->numberBetween(-5, 60))]);
                VehicleExpense::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'category' => fake()->randomElement(['Fuel', 'Maintenance', 'Insurance']), 'amount' => fake()->randomFloat(2, 500, 15000), 'expense_date' => now()->subDays(fake()->numberBetween(0, 100)), 'vendor' => fake()->company(), 'recorded_by' => $staff->first()->id]);
                VehicleDocument::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'document_type' => fake()->randomElement(['Registration', 'Insurance']), 'expiration_date' => now()->addDays(fake()->numberBetween(-5, 90))]);
                if ($i % 3 === 0) {
                    VehicleIssue::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'reported_by' => $staff->first()->id, 'title' => 'Demo vehicle issue', 'description' => 'Seeded issue for testing', 'priority' => fake()->randomElement(['low', 'high', 'critical']), 'category' => 'Other', 'status' => 'reported', 'reported_at' => now()]);
                }
            }
        }
        $this->call(AuthorizationSeeder::class);
    }
}
