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
        $brands = ['Toyota', 'Isuzu', 'Mitsubishi', 'Honda'];
        $models = ['HiAce', 'N-Series', 'L300', 'City'];
        $vehicleTypes = ['Van', 'Truck', 'Car'];
        $expenseCategories = ['Fuel', 'Maintenance', 'Insurance'];
        $documentTypes = ['Registration', 'Insurance'];
        $priorities = ['low', 'high', 'critical'];

        for ($b = 1; $b <= 3; $b++) {
            $business = Business::create(['name' => "Demo Vehicle $b", 'slug' => "demo-vehicle-$b", 'email' => "vehicle$b@example.com", 'plan_started_at' => now(), 'plan_ends_at' => now()->addDays(30), 'subscription_plan' => 'business']);
            User::create(['business_id' => $business->id, 'name' => "Demo Owner $b", 'email' => "owner$b@vh.test", 'password' => Hash::make('password'), 'role' => 'owner', 'email_verified_at' => now()]);
            $staff = collect();
            for ($s = 1; $s <= 2; $s++) {
                $staffNumber = (($b - 1) * 2) + $s;
                $staff->push(User::create(['business_id' => $business->id, 'name' => "Staff $staffNumber", 'email' => "staff$staffNumber@vh.test", 'password' => Hash::make('password'), 'role' => 'staff', 'email_verified_at' => now()]));
            }
            for ($i = 1; $i <= 8; $i++) {
                $v = Vehicle::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'plate_number' => "FD-$b".str_pad($i, 3, '0', STR_PAD_LEFT),
                    'vehicle_code' => "V-$i",
                    'brand' => $brands[($b + $i) % count($brands)],
                    'model' => $models[($b + $i) % count($models)],
                    'year' => 2018 + (($b + $i) % 9),
                    'vehicle_type' => $vehicleTypes[($b + $i) % count($vehicleTypes)],
                    'current_mileage' => 10000 + (($b * 8 + $i) * 3750),
                    'status' => 'active',
                ]);
                MaintenanceSchedule::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'maintenance_type' => 'General Maintenance Schedule', 'interval_type' => 'both', 'interval_km' => 5000, 'interval_months' => 6, 'next_service_mileage' => $v->current_mileage + (($i % 4) * 1000) - 500, 'next_service_date' => now()->addDays(($i * 9) - 14)]);
                VehicleExpense::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'category' => $expenseCategories[$i % count($expenseCategories)], 'amount' => 500 + ($i * 725.50), 'expense_date' => now()->subDays($i * 7), 'vendor' => "Demo Vendor $i", 'recorded_by' => $staff->first()->id]);
                VehicleDocument::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'document_type' => $documentTypes[$i % count($documentTypes)], 'expiration_date' => now()->addDays(($i * 12) - 10)]);
                if ($i % 3 === 0) {
                    VehicleIssue::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $v->id, 'reported_by' => $staff->first()->id, 'title' => 'Demo vehicle issue', 'description' => 'Seeded issue for testing', 'priority' => $priorities[$i % count($priorities)], 'category' => 'Other', 'status' => 'reported', 'reported_at' => now()]);
                }
            }
        }
        $this->call(AuthorizationSeeder::class);
    }
}
