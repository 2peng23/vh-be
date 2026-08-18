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

        for ($businessNumber = 1; $businessNumber <= 3; $businessNumber++) {
            $business = Business::create(['name' => "Demo Vehicle $businessNumber", 'slug' => "demo-vehicle-$businessNumber", 'email' => "vehicle$businessNumber@example.com", 'plan_started_at' => now(), 'plan_ends_at' => now()->addDays(30), 'subscription_plan' => 'business']);
            User::create(['business_id' => $business->id, 'name' => "Demo Owner $businessNumber", 'email' => "owner$businessNumber@vh.test", 'password' => Hash::make('password'), 'role' => 'owner', 'email_verified_at' => now()]);
            $staff = collect();
            for ($staffIndex = 1; $staffIndex <= 2; $staffIndex++) {
                $staffNumber = (($businessNumber - 1) * 2) + $staffIndex;
                $staff->push(User::create(['business_id' => $business->id, 'name' => "Staff $staffNumber", 'email' => "staff$staffNumber@vh.test", 'password' => Hash::make('password'), 'role' => 'staff', 'email_verified_at' => now()]));
            }
            for ($vehicleNumber = 1; $vehicleNumber <= 8; $vehicleNumber++) {
                $vehicle = Vehicle::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'plate_number' => "FD-$businessNumber".str_pad($vehicleNumber, 3, '0', STR_PAD_LEFT),
                    'vehicle_code' => "V-$vehicleNumber",
                    'brand' => $brands[($businessNumber + $vehicleNumber) % count($brands)],
                    'model' => $models[($businessNumber + $vehicleNumber) % count($models)],
                    'year' => 2018 + (($businessNumber + $vehicleNumber) % 9),
                    'vehicle_type' => $vehicleTypes[($businessNumber + $vehicleNumber) % count($vehicleTypes)],
                    'current_mileage' => 10000 + (($businessNumber * 8 + $vehicleNumber) * 3750),
                    'status' => 'active',
                ]);
                MaintenanceSchedule::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'General Maintenance Schedule', 'interval_type' => 'both', 'interval_km' => 5000, 'interval_months' => 6, 'next_service_mileage' => $vehicle->current_mileage + (($vehicleNumber % 4) * 1000) - 500, 'next_service_date' => now()->addDays(($vehicleNumber * 9) - 14)]);
                VehicleExpense::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $vehicle->id, 'category' => $expenseCategories[$vehicleNumber % count($expenseCategories)], 'amount' => 500 + ($vehicleNumber * 725.50), 'expense_date' => now()->subDays($vehicleNumber * 7), 'vendor' => "Demo Vendor $vehicleNumber", 'recorded_by' => $staff->first()->id]);
                VehicleDocument::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $vehicle->id, 'document_type' => $documentTypes[$vehicleNumber % count($documentTypes)], 'expiration_date' => now()->addDays(($vehicleNumber * 12) - 10)]);
                if ($vehicleNumber % 3 === 0) {
                    VehicleIssue::withoutGlobalScopes()->create(['business_id' => $business->id, 'vehicle_id' => $vehicle->id, 'reported_by' => $staff->first()->id, 'title' => 'Demo vehicle issue', 'description' => 'Seeded issue for testing', 'priority' => $priorities[$vehicleNumber % count($priorities)], 'category' => 'Other', 'status' => 'reported', 'reported_at' => now()]);
                }
            }
        }
        $this->call(AuthorizationSeeder::class);
    }
}
