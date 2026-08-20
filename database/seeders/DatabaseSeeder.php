<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MileageLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);

        $vehicleCatalog = [
            ['brand' => 'Isuzu', 'model' => 'N-Series', 'type' => 'Van', 'color' => 'Black'],
            ['brand' => 'Mitsubishi', 'model' => 'Fuso Canter', 'type' => 'Truck', 'color' => 'White'],
            ['brand' => 'Toyota', 'model' => 'HiAce', 'type' => 'Van', 'color' => 'Silver'],
            ['brand' => 'Hino', 'model' => '300 Series', 'type' => 'Truck', 'color' => 'Blue'],
            ['brand' => 'Nissan', 'model' => 'Urvan', 'type' => 'Van', 'color' => 'Gray'],
            ['brand' => 'Hyundai', 'model' => 'H-100', 'type' => 'Truck', 'color' => 'Red'],
            ['brand' => 'Foton', 'model' => 'Gratour', 'type' => 'Van', 'color' => 'White'],
            ['brand' => 'Suzuki', 'model' => 'Carry', 'type' => 'Truck', 'color' => 'Green'],
        ];

        $driverNames = ['Mark Santos', 'Leo Cruz', 'Ramon Dela Pena', 'Carlo Reyes', 'Miguel Navarro'];
        $maintenanceTypes = ['General Maintenance Schedule', 'Brake Inspection'];
        $issueStatuses = ['reported', 'for_inspection', 'approved', 'in_repair', 'completed'];
        $issuePriorities = ['low', 'medium', 'high', 'critical'];

        for ($businessNumber = 1; $businessNumber <= 3; $businessNumber++) {
            $business = Business::create([
                'name' => "Demo Vehicle $businessNumber",
                'slug' => "demo-vehicle-$businessNumber",
                'email' => "vehicle$businessNumber@example.com",
                'plan_started_at' => now(),
                'plan_ends_at' => now()->addDays(30),
                'subscription_plan' => 'business',
            ]);

            $owner = User::create([
                'business_id' => $business->id,
                'name' => "Demo Owner $businessNumber",
                'email' => "owner$businessNumber@vh.test",
                'password' => Hash::make('password'),
                'role' => 'owner',
                'email_verified_at' => now(),
            ]);

            $staff = collect();
            for ($staffIndex = 1; $staffIndex <= 2; $staffIndex++) {
                $staffNumber = (($businessNumber - 1) * 2) + $staffIndex;
                $staff->push(User::create([
                    'business_id' => $business->id,
                    'name' => "Staff $staffNumber",
                    'email' => "staff$staffNumber@vh.test",
                    'password' => Hash::make('password'),
                    'role' => 'staff',
                    'email_verified_at' => now(),
                ]));
            }

            $drivers = collect($driverNames)->map(function (string $name, int $index) use ($business, $businessNumber) {
                $driverNumber = $index + 1;

                return Driver::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'employee_number' => 'DRV-'.$businessNumber.'-'.str_pad((string) $driverNumber, 3, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'email' => strtolower(str_replace(' ', '.', $name)).".b{$businessNumber}@vh.test",
                    'phone' => '09'.(string) (520000000 + ($businessNumber * 1000) + $driverNumber),
                    'license_number' => 'N'.str_pad((string) ($businessNumber * 100000 + $driverNumber * 7281), 10, '0', STR_PAD_LEFT),
                    'license_type' => $driverNumber % 2 === 0 ? 'Professional B2' : 'Professional C',
                    'license_expiration' => now()->addMonths(6 + ($driverNumber * 3))->toDateString(),
                    'date_hired' => now()->subMonths(18 + $driverNumber)->toDateString(),
                    'status' => $driverNumber === 5 ? 'on_leave' : 'active',
                ]);
            });

            for ($vehicleNumber = 1; $vehicleNumber <= 8; $vehicleNumber++) {
                $catalog = $vehicleCatalog[$vehicleNumber - 1];
                $currentMileage = 10000 + (($businessNumber * 8 + $vehicleNumber) * 3750);
                $staffUser = $staff[$vehicleNumber % $staff->count()];
                $driver = $drivers[($vehicleNumber - 1) % $drivers->count()];

                $vehicle = Vehicle::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'plate_number' => "FD-$businessNumber".str_pad((string) $vehicleNumber, 3, '0', STR_PAD_LEFT),
                    'vehicle_code' => "V-$vehicleNumber",
                    'brand' => $catalog['brand'],
                    'model' => $catalog['model'],
                    'year' => 2018 + (($businessNumber + $vehicleNumber) % 9),
                    'vehicle_type' => $catalog['type'],
                    'color' => $catalog['color'],
                    'vin' => 'VIN'.$businessNumber.str_pad((string) $vehicleNumber, 6, '0', STR_PAD_LEFT).'VH',
                    'engine_number' => 'ENG-'.$businessNumber.'-'.$vehicleNumber.'-'.str_pad((string) ($currentMileage % 10000), 4, '0', STR_PAD_LEFT),
                    'chassis_number' => 'CHS-'.$businessNumber.'-'.$vehicleNumber.'-'.str_pad((string) ($currentMileage % 9000), 4, '0', STR_PAD_LEFT),
                    'current_mileage' => $currentMileage,
                    'acquisition_date' => now()->subMonths(20 + $vehicleNumber)->toDateString(),
                    'acquisition_cost' => 180000 + ($vehicleNumber * 20000) + ($businessNumber * 15000),
                    'status' => $vehicleNumber === 6 ? 'maintenance' : 'active',
                    'notes' => 'Seeded fleet unit for demo workflows.',
                ]);

                VehicleAssignment::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_id' => $driver->id,
                    'assigned_by' => $owner->id,
                    'assigned_at' => now()->subDays(45 + $vehicleNumber),
                    'returned_at' => $vehicleNumber === 8 ? now()->subDays(5) : null,
                    'status' => $vehicleNumber === 8 ? 'returned' : 'active',
                    'notes' => $vehicleNumber === 8 ? 'Returned for reassignment after route rotation.' : 'Assigned to daily fleet operations.',
                ]);

                for ($logIndex = 4; $logIndex >= 0; $logIndex--) {
                    MileageLog::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'recorded_by' => $staffUser->id,
                        'mileage' => max(0, $currentMileage - ($logIndex * (750 + ($vehicleNumber * 35)))),
                        'recorded_at' => now()->subDays($logIndex * 10),
                        'notes' => $logIndex === 0 ? 'Latest odometer reading.' : 'Routine mileage update.',
                        'is_override' => false,
                    ]);
                }

                $schedules = collect($maintenanceTypes)->map(function (string $maintenanceType, int $index) use ($business, $currentMileage, $vehicle, $vehicleNumber) {
                    $isGeneral = $index === 0;

                    return MaintenanceSchedule::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'maintenance_type' => $maintenanceType,
                        'interval_type' => 'both',
                        'interval_km' => $isGeneral ? 5000 : 10000,
                        'interval_months' => $isGeneral ? 6 : 12,
                        'last_service_mileage' => $currentMileage - ($isGeneral ? 4500 : 8500),
                        'last_service_date' => now()->subMonths($isGeneral ? 5 : 10)->toDateString(),
                        'next_service_mileage' => $currentMileage + ($isGeneral ? (($vehicleNumber % 4) * 1000) - 500 : 2500 + ($vehicleNumber * 300)),
                        'next_service_date' => now()->addDays($isGeneral ? (($vehicleNumber * 9) - 14) : (30 + ($vehicleNumber * 5)))->toDateString(),
                        'reminder_km' => $isGeneral ? 1000 : 2000,
                        'reminder_days' => $isGeneral ? 14 : 30,
                        'status' => 'active',
                    ]);
                });

                foreach ($schedules as $scheduleIndex => $schedule) {
                    $laborCost = 900 + ($vehicleNumber * 120) + ($scheduleIndex * 350);
                    $partsCost = 1450 + ($vehicleNumber * 180) + ($scheduleIndex * 475);
                    $otherCost = 250 + ($scheduleIndex * 125);
                    $totalCost = $laborCost + $partsCost + $otherCost;

                    $record = MaintenanceRecord::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'maintenance_schedule_id' => $schedule->id,
                        'performed_by' => $staffUser->id,
                        'service_provider' => $scheduleIndex === 0 ? 'North Fleet Service Center' : 'RoadReady Auto Care',
                        'service_date' => now()->subDays(42 - ($scheduleIndex * 16) + $vehicleNumber)->toDateString(),
                        'mileage' => $currentMileage - (2200 - ($scheduleIndex * 700)),
                        'maintenance_type' => $schedule->maintenance_type,
                        'description' => $scheduleIndex === 0 ? 'Oil, filters, belts, and general safety inspection.' : 'Brake cleaning, lining check, and road test.',
                        'labor_cost' => $laborCost,
                        'parts_cost' => $partsCost,
                        'other_cost' => $otherCost,
                        'next_service_date' => now()->addMonths($scheduleIndex === 0 ? 5 : 10)->toDateString(),
                        'next_service_mileage' => $currentMileage + ($scheduleIndex === 0 ? 5000 : 10000),
                        'status' => 'completed',
                        'notes' => 'Seeded completed service record.',
                    ]);
                    $record->forceFill(['total_cost' => $totalCost])->save();

                    foreach ([
                        ['name' => $scheduleIndex === 0 ? 'Engine oil' : 'Brake cleaner', 'unit' => 650 + ($vehicleNumber * 15), 'quantity' => $scheduleIndex === 0 ? 4 : 2],
                        ['name' => $scheduleIndex === 0 ? 'Oil filter' : 'Brake lining kit', 'unit' => 520 + ($vehicleNumber * 20), 'quantity' => 1],
                    ] as $partIndex => $part) {
                        $maintenancePart = MaintenancePart::withoutGlobalScopes()->create([
                            'business_id' => $business->id,
                            'maintenance_record_id' => $record->id,
                            'part_name' => $part['name'],
                            'part_number' => 'PART-'.$vehicleNumber.'-'.$scheduleIndex.'-'.$partIndex,
                            'quantity' => $part['quantity'],
                            'unit_cost' => $part['unit'],
                            'supplier' => 'Fleet Parts PH',
                        ]);
                        $maintenancePart->forceFill(['total_cost' => $part['quantity'] * $part['unit']])->save();
                    }

                    VehicleExpense::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'category' => 'Maintenance',
                        'amount' => $totalCost,
                        'expense_date' => $record->service_date->toDateString(),
                        'vendor' => $record->service_provider,
                        'description' => $record->maintenance_type,
                        'recorded_by' => $staffUser->id,
                        'maintenance_record_id' => $record->id,
                        'is_generated' => true,
                    ]);
                }

                for ($fuelIndex = 1; $fuelIndex <= 3; $fuelIndex++) {
                    $liters = 38 + ($vehicleNumber * 1.75) + ($fuelIndex * 2);
                    $pricePerLiter = 63 + $fuelIndex;
                    $fuelLog = FuelLog::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'recorded_by' => $staffUser->id,
                        'fuel_date' => now()->subDays($fuelIndex * 8)->toDateString(),
                        'mileage' => $currentMileage - ($fuelIndex * 620),
                        'liters' => $liters,
                        'price_per_liter' => $pricePerLiter,
                        'total_amount' => $liters * $pricePerLiter,
                        'fuel_type' => $catalog['type'] === 'Truck' ? 'Diesel' : 'Gasoline',
                        'station' => $fuelIndex % 2 === 0 ? 'Shell' : 'Petron',
                        'notes' => 'Route refuel entry.',
                    ]);

                    VehicleExpense::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'category' => 'Fuel',
                        'amount' => $fuelLog->total_amount,
                        'expense_date' => $fuelLog->fuel_date->toDateString(),
                        'vendor' => $fuelLog->station,
                        'description' => $fuelLog->fuel_type.' refuel',
                        'recorded_by' => $staffUser->id,
                        'fuel_log_id' => $fuelLog->id,
                        'is_generated' => true,
                    ]);
                }

                foreach ([
                    ['category' => 'Insurance', 'amount' => 4200 + ($vehicleNumber * 350), 'vendor' => 'Pioneer Insurance', 'days' => 110],
                    ['category' => 'Registration', 'amount' => 2150 + ($vehicleNumber * 180), 'vendor' => 'LTO', 'days' => 95],
                    ['category' => 'Toll and parking', 'amount' => 850 + ($vehicleNumber * 75), 'vendor' => 'Operations petty cash', 'days' => 14],
                ] as $expense) {
                    VehicleExpense::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'category' => $expense['category'],
                        'amount' => $expense['amount'],
                        'expense_date' => now()->subDays($expense['days'] - $vehicleNumber)->toDateString(),
                        'vendor' => $expense['vendor'],
                        'description' => $expense['category'].' expense for seeded demo vehicle.',
                        'recorded_by' => $staffUser->id,
                    ]);
                }

                foreach ([
                    ['type' => 'Registration', 'number' => 'REG-'.$businessNumber.'-'.$vehicleNumber, 'expires' => ($vehicleNumber * 18) - 20],
                    ['type' => 'Insurance', 'number' => 'INS-'.$businessNumber.'-'.$vehicleNumber, 'expires' => 80 + ($vehicleNumber * 12)],
                    ['type' => 'Emission Test', 'number' => 'EMI-'.$businessNumber.'-'.$vehicleNumber, 'expires' => 25 + ($vehicleNumber * 7)],
                ] as $document) {
                    $expiration = now()->addDays($document['expires']);

                    VehicleDocument::withoutGlobalScopes()->create([
                        'business_id' => $business->id,
                        'vehicle_id' => $vehicle->id,
                        'document_type' => $document['type'],
                        'document_number' => $document['number'],
                        'issue_date' => $expiration->copy()->subYear()->toDateString(),
                        'expiration_date' => $expiration->toDateString(),
                        'file_path' => 'demo/documents/'.$businessNumber.'/'.$vehicleNumber.'/'.strtolower(str_replace(' ', '-', $document['type'])).'.pdf',
                        'notes' => 'Seeded document for demo review.',
                        'status' => $expiration->isPast() ? 'expired' : 'active',
                    ]);
                }

                $issueStatus = $issueStatuses[($vehicleNumber - 1) % count($issueStatuses)];
                $isResolved = $issueStatus === 'completed';
                VehicleIssue::withoutGlobalScopes()->create([
                    'business_id' => $business->id,
                    'vehicle_id' => $vehicle->id,
                    'reported_by' => $staffUser->id,
                    'assigned_to' => $staff->first()->id,
                    'assigned_to_name' => $staff->first()->name,
                    'title' => $vehicleNumber % 2 === 0 ? 'Unusual brake noise' : 'Dashboard warning light',
                    'description' => $vehicleNumber % 2 === 0
                        ? 'Driver reported grinding noise during city delivery route.'
                        : 'Warning indicator appeared during pre-trip inspection.',
                    'priority' => $issuePriorities[$vehicleNumber % count($issuePriorities)],
                    'category' => $vehicleNumber % 2 === 0 ? 'Brakes' : 'Engine',
                    'status' => $issueStatus,
                    'mileage' => $currentMileage - 120,
                    'reported_at' => now()->subDays(10 + $vehicleNumber),
                    'resolved_at' => $isResolved ? now()->subDays(2) : null,
                    'resolution_notes' => $isResolved ? 'Issue verified and repaired during scheduled service.' : null,
                    'estimated_cost' => 2500 + ($vehicleNumber * 250),
                    'actual_cost' => $isResolved ? 2200 + ($vehicleNumber * 240) : null,
                ]);
            }

            if ($businessNumber === 1) {
                $this->seedOwnerNotifications($owner, $business->id);
            }
        }

        $this->call(AuthorizationSeeder::class);
    }

    private function seedOwnerNotifications(User $owner, int $businessId): void
    {
        $document = VehicleDocument::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderBy('expiration_date')
            ->first();
        $schedule = MaintenanceSchedule::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderBy('next_service_date')
            ->first();
        $issue = VehicleIssue::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('status', '!=', 'completed')
            ->orderByDesc('reported_at')
            ->first();

        DB::table('notifications')->insert([
            [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\VehicleReminder',
                'notifiable_type' => User::class,
                'notifiable_id' => $owner->id,
                'data' => json_encode([
                    'title' => 'Vehicle document reminder',
                    'message' => "{$document->document_type} for {$document->vehicle->brand} {$document->vehicle->model} needs attention.",
                    'vehicle_id' => $document->vehicle_id,
                ]),
                'read_at' => null,
                'created_at' => now()->subMinutes(25),
                'updated_at' => now()->subMinutes(25),
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\VehicleReminder',
                'notifiable_type' => User::class,
                'notifiable_id' => $owner->id,
                'data' => json_encode([
                    'title' => 'Maintenance reminder',
                    'message' => "{$schedule->maintenance_type} is scheduled for {$schedule->vehicle->brand} {$schedule->vehicle->model}.",
                    'vehicle_id' => $schedule->vehicle_id,
                ]),
                'read_at' => null,
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(15),
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\VehicleReminder',
                'notifiable_type' => User::class,
                'notifiable_id' => $owner->id,
                'data' => json_encode([
                    'title' => 'Open issue reminder',
                    'message' => "{$issue->title} is still open on {$issue->vehicle->brand} {$issue->vehicle->model}.",
                    'vehicle_id' => $issue->vehicle_id,
                ]),
                'read_at' => null,
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ],
        ]);
    }
}
