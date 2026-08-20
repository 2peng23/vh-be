<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\MaintenanceSchedule;
use App\Models\PaymentMethod;
use App\Models\PlanTransaction;
use App\Models\SubscriptionPlanOffering;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Support\SubscriptionPlans;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    private function user(string $slug, string $role = 'owner'): User
    {
        $b = Business::create(['name' => $slug, 'slug' => $slug, 'email' => "$slug@example.com"]);

        return User::create(['business_id' => $b->id, 'name' => 'Test', 'email' => "user-$slug@example.com", 'password' => 'password', 'role' => $role, 'email_verified_at' => now()]);
    }

    private function vehicle(User $u, string $plate): Vehicle
    {
        return Vehicle::withoutGlobalScopes()->create(['business_id' => $u->business_id, 'plate_number' => $plate, 'brand' => 'Toyota', 'model' => 'HiAce', 'vehicle_type' => 'Van', 'current_mileage' => 1000]);
    }

    public function test_user_permissions_control_crud_and_are_returned_by_me(): void
    {
        $owner = $this->user('permission-owner');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonFragment(['vehicles.create']);

        $owner->revokePermissionTo('vehicles.create');

        $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'DENIED-1',
            'brand' => 'Toyota',
            'model' => 'Vios',
            'vehicle_type' => 'Car',
        ])->assertForbidden();
    }

    public function test_login_returns_effective_user_permissions(): void
    {
        $owner = $this->user('login-permissions');

        $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.permissions.0', 'dashboard.view')
            ->assertJsonFragment(['vehicles.view']);
    }

    public function test_owner_and_staff_cannot_access_an_ended_plan(): void
    {
        $owner = $this->user('ended-plan');
        $owner->business->update(['plan_ends_at' => now()->subDay()]);
        $staff = User::create([
            'business_id' => $owner->business_id,
            'name' => 'Ended Staff',
            'email' => 'ended-staff@example.com',
            'password' => 'password',
            'role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        foreach ([$owner, $staff] as $user) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertForbidden()->assertJsonPath('code', 'PLAN_ENDED');
        }
        $this->assertDatabaseHas('businesses', [
            'id' => $owner->business_id,
            'subscription_status' => 'past_due',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_ENDED');
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/support/messages')->assertForbidden();
        $this->postJson('/api/v1/support/messages', ['message' => 'Staff should not send this.'])->assertForbidden();
    }

    public function test_inactive_business_blocks_owner_and_staff_with_a_distinct_response(): void
    {
        $owner = $this->user('inactive-business');
        $staff = User::create([
            'business_id' => $owner->business_id,
            'name' => 'Inactive Staff',
            'email' => 'inactive-staff@example.com',
            'password' => 'password',
            'role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $owner->business->update(['status' => 'inactive']);

        foreach ([$owner, $staff] as $user) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertForbidden()->assertJsonPath('code', 'BUSINESS_INACTIVE');
        }

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'BUSINESS_INACTIVE');
    }

    public function test_registration_creates_business_owner_trial_and_token(): void
    {
        $this->postJson('/api/v1/auth/register', ['business_name' => 'Acme Vehicle', 'owner_name' => 'Joel', 'email' => 'owner@acme.test', 'phone' => '09170000000', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertCreated()->assertJsonPath('data.user.role', 'owner')->assertJsonPath('data.business.subscription_plan', 'trial')->assertJsonPath('data.business.subscription_status', 'active')->assertJsonStructure(['data' => ['token']]);
        $this->assertDatabaseHas('businesses', ['slug' => 'acme-vehicle']);
    }

    public function test_tenant_cannot_read_or_mutate_other_business_vehicle(): void
    {
        $a = $this->user('alpha');
        $b = $this->user('beta');
        $foreign = $this->vehicle($b, 'BETA-1');
        Sanctum::actingAs($a);
        $this->getJson('/api/v1/vehicles/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/v1/vehicles/'.$foreign->id, ['plate_number' => 'STOLEN', 'brand' => 'X', 'model' => 'Y', 'vehicle_type' => 'Car'])->assertNotFound();
        $this->deleteJson('/api/v1/vehicles/'.$foreign->id)->assertNotFound();
    }

    public function test_plate_is_unique_only_inside_business(): void
    {
        $a = $this->user('one');
        $b = $this->user('two');
        $this->vehicle($a, 'ABC-123');
        Sanctum::actingAs($b);
        $this->postJson('/api/v1/vehicles', ['plate_number' => 'ABC-123', 'brand' => 'Honda', 'model' => 'City', 'vehicle_type' => 'Car'])->assertCreated();
    }

    public function test_vehicle_code_is_generated_from_type_and_client_value_is_ignored(): void
    {
        $owner = $this->user('vehicle-codes');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'CODE-2',
            'vehicle_code' => 'CLIENT-CODE',
            'brand' => 'Isuzu',
            'model' => 'N-Series',
            'vehicle_type' => 'Truck',
        ])->assertCreated();

        $code = $response->json('data.vehicle_code');
        $this->assertMatchesRegularExpression('/^TRK-[A-Z0-9]{6}$/', $code);
        $this->assertNotSame('CLIENT-CODE', $code);
    }

    public function test_subscription_tiers_only_limit_the_number_of_vehicles(): void
    {
        $owner = $this->user('subscription-limit');
        Sanctum::actingAs($owner);

        foreach (range(1, 10) as $index) {
            $this->vehicle($owner, "TRIAL-{$index}");
        }

        $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'TRIAL-11',
            'brand' => 'Toyota',
            'model' => 'Vios',
            'vehicle_type' => 'Car',
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle');

        $owner->business->update([
            'subscription_status' => 'active',
            'subscription_plan' => 'starter',
        ]);

        $owner->business->update(['vehicle_limit_override' => 12]);

        foreach (range(11, 12) as $index) {
            $this->postJson('/api/v1/vehicles', [
                'plate_number' => "STARTER-{$index}",
                'brand' => 'Toyota',
                'model' => 'Vios',
                'vehicle_type' => 'Car',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'STARTER-13',
            'brand' => 'Toyota',
            'model' => 'Vios',
            'vehicle_type' => 'Car',
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business.subscription.vehicle_limit', 12)
            ->assertJsonPath('data.business.subscription.vehicle_count', 12)
            ->assertJsonPath('data.business.subscription.vehicle_limit_reached', true);
    }

    public function test_mileage_rejects_rollback_and_allows_admin_audited_override(): void
    {
        $u = $this->user('miles');
        $v = $this->vehicle($u, 'M-1');
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/vehicles/$v->id/mileage", ['mileage' => 900])->assertUnprocessable()->assertJsonValidationErrors('mileage');
        $this->postJson("/api/v1/vehicles/$v->id/mileage", ['mileage' => 900, 'override' => true])->assertCreated();
        $this->assertDatabaseHas('vehicles', ['id' => $v->id, 'current_mileage' => 900]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mileage.override']);
    }

    public function test_maintenance_total_is_server_calculated(): void
    {
        $u = $this->user('maint');
        $v = $this->vehicle($u, 'MAINTENANCE-1');
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/vehicles/$v->id/maintenance", ['service_date' => now()->toDateString(), 'mileage' => 1000, 'maintenance_type' => 'General Maintenance Schedule', 'service_provider' => 'Vehicle Hub Workshop', 'labor_cost' => 100, 'parts_cost' => 200, 'other_cost' => 50, 'notes' => 'Other cost includes towing and shop supplies.'])->assertCreated()->assertJsonPath('data.total_cost', '350.00');
        $this->getJson("/api/v1/vehicles/$v->id/maintenance")
            ->assertOk()
            ->assertJsonPath('data.0.performer.name', $u->name)
            ->assertJsonPath('data.0.service_provider', 'Vehicle Hub Workshop')
            ->assertJsonPath('data.0.notes', 'Other cost includes towing and shop supplies.');
    }

    public function test_maintenance_can_be_updated_and_deleted(): void
    {
        $user = $this->user('maint-crud');
        $vehicle = $this->vehicle($user, 'MAINTENANCE-CRUD');
        Sanctum::actingAs($user);

        $id = $this->postJson("/api/v1/vehicles/{$vehicle->id}/maintenance", [
            'service_date' => now()->toDateString(),
            'mileage' => 1000,
            'maintenance_type' => 'Oil change',
            'labor_cost' => 100,
            'parts_cost' => 200,
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('vehicle_expenses', [
            'maintenance_record_id' => $id,
            'category' => 'Maintenance',
            'amount' => 300,
        ]);

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/maintenance/{$id}", [
            'labor_cost' => 150,
        ])->assertOk()->assertJsonPath('data.total_cost', '350.00');
        $this->assertDatabaseHas('vehicle_expenses', [
            'maintenance_record_id' => $id,
            'amount' => 350,
        ]);

        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}/maintenance/{$id}")->assertOk();
        $this->assertSoftDeleted('maintenance_records', ['id' => $id]);
        $this->assertSoftDeleted('vehicle_expenses', ['maintenance_record_id' => $id]);
    }

    public function test_linked_maintenance_advances_its_schedule(): void
    {
        $user = $this->user('linked-maintenance');
        $vehicle = $this->vehicle($user, 'LINKED-MAINT');
        $schedule = MaintenanceSchedule::withoutGlobalScopes()->create([
            'business_id' => $user->business_id,
            'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'Oil change',
            'interval_type' => 'both',
            'interval_km' => 5000,
            'interval_months' => 6,
            'next_service_mileage' => 1000,
            'next_service_date' => '2026-01-01',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/maintenance", [
            'maintenance_schedule_id' => $schedule->id,
            'service_date' => '2026-08-14',
            'mileage' => 1200,
            'maintenance_type' => 'Oil change',
        ])->assertCreated();

        $this->assertDatabaseHas('maintenance_schedules', [
            'id' => $schedule->id,
            'last_service_date' => '2026-08-14 00:00:00',
            'last_service_mileage' => 1200,
            'next_service_date' => '2027-02-14 00:00:00',
            'next_service_mileage' => 6200,
            'status' => 'active',
        ]);
    }

    public function test_schedule_initial_due_values_are_calculated_from_vehicle_and_creation_date(): void
    {
        $this->travelTo('2026-08-14 10:00:00');
        $user = $this->user('initial-schedule-due');
        $vehicle = $this->vehicle($user, 'INITIAL-DUE');
        $vehicle->update(['current_mileage' => 2500]);
        Sanctum::actingAs($user);

        $scheduleId = $this->postJson("/api/v1/vehicles/{$vehicle->id}/schedules", [
            'maintenance_type' => 'Change Oil',
            'interval_type' => 'both',
            'interval_km' => 5000,
            'interval_months' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.next_service_mileage', 7500)
            ->json('data.id');

        $this->assertDatabaseHas('maintenance_schedules', [
            'id' => $scheduleId,
            'next_service_mileage' => 7500,
            'next_service_date' => '2027-01-14 00:00:00',
        ]);
    }

    public function test_fuel_expense_is_created_synchronized_and_deleted_with_its_log(): void
    {
        $user = $this->user('fuel-expense-sync');
        $vehicle = $this->vehicle($user, 'FUEL-SYNC');
        Sanctum::actingAs($user);

        $fuelId = $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel", [
            'fuel_date' => now()->toDateString(),
            'mileage' => 1200,
            'liters' => 20,
            'price_per_liter' => 60,
            'station' => 'Sample Fuel Station',
        ])->assertCreated()->json('data.id');

        $expenseId = VehicleExpense::where('fuel_log_id', $fuelId)->firstOrFail()->id;
        $this->assertDatabaseHas('vehicle_expenses', [
            'id' => $expenseId,
            'category' => 'Fuel',
            'amount' => 1200,
        ]);

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/fuel/{$fuelId}", [
            'price_per_liter' => 65,
        ])->assertOk();
        $this->assertDatabaseHas('vehicle_expenses', ['id' => $expenseId, 'amount' => 1300]);

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/expenses/{$expenseId}", [
            'amount' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('expense');

        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}/fuel/{$fuelId}")->assertOk();
        $this->assertSoftDeleted('vehicle_expenses', ['id' => $expenseId]);
    }

    public function test_mileage_edit_and_delete_recalculate_current_reading(): void
    {
        $user = $this->user('mileage-crud');
        $vehicle = $this->vehicle($user, 'MILE-CRUD');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/mileage", [
            'mileage' => 1200,
            'recorded_at' => now()->subDay()->toISOString(),
        ])->assertCreated();
        $latestId = $this->postJson("/api/v1/vehicles/{$vehicle->id}/mileage", [
            'mileage' => 1300,
            'recorded_at' => now()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/mileage/{$latestId}", ['mileage' => 1400])
            ->assertOk();
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'current_mileage' => 1400]);

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/mileage/{$latestId}", ['mileage' => 1300])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mileage');
        $this->putJson("/api/v1/vehicles/{$vehicle->id}/mileage/{$latestId}", ['mileage' => 1300, 'override' => true])
            ->assertOk()
            ->assertJsonPath('data.is_override', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mileage.override']);

        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}/mileage/{$latestId}")->assertOk();
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'current_mileage' => 1200]);
    }

    public function test_nested_expense_can_be_updated_and_deleted(): void
    {
        $user = $this->user('expense-crud');
        $vehicle = $this->vehicle($user, 'EXP-CRUD');
        Sanctum::actingAs($user);

        $id = $this->postJson("/api/v1/vehicles/{$vehicle->id}/expenses", [
            'category' => 'Fuel',
            'amount' => 500,
            'expense_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/expenses/{$id}", ['amount' => 750])
            ->assertOk()->assertJsonPath('data.amount', '750.00');
        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}/expenses/{$id}")->assertOk();
        $this->assertSoftDeleted('vehicle_expenses', ['id' => $id]);
    }

    public function test_issue_returns_user_names_and_assignee_must_belong_to_business(): void
    {
        $reporter = $this->user('issue-users-a');
        $foreignUser = $this->user('issue-users-b');
        $vehicle = $this->vehicle($reporter, 'ISSUE-USERS');
        Sanctum::actingAs($reporter);

        $this->getJson('/api/v1/assignees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', $reporter->name);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/issues", [
            'title' => 'Brake inspection',
            'description' => 'Brake pedal feels soft.',
            'category' => 'Brakes',
            'assigned_to' => $foreignUser->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/issues", [
            'title' => 'Brake inspection',
            'description' => 'Brake pedal feels soft.',
            'category' => 'Brakes',
            'assigned_to_name' => 'Juan Dela Cruz - Main Workshop',
        ])->assertCreated();

        $this->getJson("/api/v1/vehicles/{$vehicle->id}/issues")
            ->assertOk()
            ->assertJsonPath('data.0.reporter.name', $reporter->name)
            ->assertJsonPath('data.0.assigned_to_name', 'Juan Dela Cruz - Main Workshop');
    }

    public function test_audit_log_is_tenant_scoped_manager_only_and_exportable(): void
    {
        $owner = $this->user('audit-a');
        $otherOwner = $this->user('audit-b');
        $vehicle = $this->vehicle($owner, 'AUDIT-A');
        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/vehicles/{$vehicle->id}", [
            'plate_number' => 'AUDIT-A',
            'brand' => 'Toyota',
            'model' => 'HiAce Updated',
            'vehicle_type' => 'Van',
        ])->assertOk();

        $this->getJson('/api/v1/audit-logs?action=vehicle.updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', $owner->name)
            ->assertJsonPath('data.0.entity_name', 'Vehicle');
        $this->get('/api/v1/audit-logs?format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        Sanctum::actingAs($otherOwner);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(0, 'data');

        $mechanic = User::create([
            'business_id' => $owner->business_id,
            'name' => 'Mechanic',
            'email' => 'mechanic@audit.test',
            'password' => 'password',
            'role' => 'staff',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($mechanic);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_staff_accounts_are_not_limited_by_the_vehicle_subscription(): void
    {
        $owner = $this->user('staff-limit');
        Sanctum::actingAs($owner);

        for ($index = 1; $index <= 4; $index++) {
            $response = $this->postJson('/api/v1/staff', [
                'name' => "Staff {$index}",
                'email' => "staff{$index}@limit.test",
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertCreated();
            $response->assertJsonPath('data.role', 'staff');
        }

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.created']);
    }

    public function test_driver_employee_id_is_generated_and_profile_file_is_private(): void
    {
        Storage::fake();
        $owner = $this->user('acme-fleet');
        $owner->business->update(['name' => 'Acme Fleet']);
        Sanctum::actingAs($owner);

        $response = $this->post('/api/v1/drivers', [
            'name' => 'Test Driver',
            'status' => 'active',
            'driver_photo' => UploadedFile::fake()->image('driver.png'),
            'license_photo' => UploadedFile::fake()->image('license.png'),
        ])->assertCreated();

        $employeeId = $response->json('data.employee_number');
        $this->assertMatchesRegularExpression('/^ACMEFLEET-[A-Z0-9]{6}$/', $employeeId);
        $path = $response->json('data.profile_photo');
        $licensePath = $response->json('data.license_photo');
        Storage::assertExists($path);
        Storage::assertExists($licensePath);
        $this->get("/api/v1/drivers/{$response->json('data.id')}/files/driver")->assertOk();
        $this->get("/api/v1/drivers/{$response->json('data.id')}/files/license")->assertOk();

        $updated = $this->post("/api/v1/drivers/{$response->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Updated Driver',
            'employee_number' => $employeeId,
            'status' => 'inactive',
            'driver_photo' => UploadedFile::fake()->image('new-driver.png'),
        ])->assertOk();
        $this->assertSame('Updated Driver', $updated->json('data.name'));
        Storage::assertMissing($path);
        Storage::assertExists($updated->json('data.profile_photo'));

        $this->putJson("/api/v1/drivers/{$response->json('data.id')}", [
            'status' => 'completed',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $otherOwner = $this->user('other-driver-owner');
        Sanctum::actingAs($otherOwner);
        $this->get("/api/v1/drivers/{$response->json('data.id')}/files/driver")->assertNotFound();
    }

    public function test_owner_can_recreate_and_restore_their_deleted_staff_email(): void
    {
        $owner = $this->user('staff-restore');
        $staff = User::create([
            'business_id' => $owner->business_id,
            'name' => 'Old Staff Name',
            'email' => 'restored-staff@example.com',
            'password' => 'OldPassword123!',
            'role' => 'staff',
            'status' => 'inactive',
        ]);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/staff/{$staff->id}")->assertOk();

        $this->postJson('/api/v1/staff', [
            'name' => 'Restored Staff',
            'email' => 'restored-staff@example.com',
            'phone' => '09171234567',
            'status' => 'active',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertCreated()
            ->assertJsonPath('message', 'Staff account restored.')
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.name', 'Restored Staff');

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'name' => 'Restored Staff',
            'email' => 'restored-staff@example.com',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'staff.restored',
            'entity_id' => $staff->id,
        ]);
    }

    public function test_inactive_staff_cannot_log_in_or_use_an_existing_token(): void
    {
        $owner = $this->user('inactive-staff-access');
        $staff = User::create([
            'business_id' => $owner->business_id,
            'name' => 'Inactive Staff',
            'email' => 'inactive-access@example.com',
            'password' => 'Password123!',
            'role' => 'staff',
            'status' => 'active',
        ]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/me')->assertOk();

        User::whereKey($staff->id)->update(['status' => 'inactive']);

        $this->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'STAFF_INACTIVE')
            ->assertJsonPath('message', 'Your staff account is inactive. Please contact your business owner.');

        $this->postJson('/api/v1/auth/login', [
            'email' => $staff->email,
            'password' => 'Password123!',
        ])->assertForbidden()
            ->assertJsonPath('code', 'STAFF_INACTIVE')
            ->assertJsonPath('data.user.business.email', $owner->business->email);
    }

    public function test_owner_cannot_restore_a_deleted_staff_email_from_another_business(): void
    {
        $firstOwner = $this->user('staff-email-first-owner');
        $secondOwner = $this->user('staff-email-second-owner');
        $staff = User::create([
            'business_id' => $firstOwner->business_id,
            'name' => 'First Owner Staff',
            'email' => 'shared-deleted-staff@example.com',
            'password' => 'Password123!',
            'role' => 'staff',
        ]);
        $staff->delete();
        Sanctum::actingAs($secondOwner);

        $this->postJson('/api/v1/staff', [
            'name' => 'Second Owner Staff',
            'email' => 'shared-deleted-staff@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertDatabaseMissing('users', [
            'business_id' => $secondOwner->business_id,
            'email' => 'shared-deleted-staff@example.com',
        ]);
    }

    public function test_super_admin_can_manage_all_businesses_and_users(): void
    {
        $businessOwner = $this->user('platform-tenant');
        $staff = User::create([
            'business_id' => $businessOwner->business_id,
            'name' => 'Tenant Staff',
            'email' => 'tenant-staff@vehiclehub.test',
            'password' => 'password',
            'role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $superAdmin = User::create([
            'business_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform@vehiclehub.test',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/superadmin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.businesses', 1)
            ->assertJsonPath('data.users', 2);
        $this->getJson('/api/v1/superadmin/businesses')
            ->assertOk()
            ->assertJsonPath('data.0.users.0.email', $businessOwner->email);
        $this->getJson('/api/v1/superadmin/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $businessOwner->email)
            ->assertJsonPath('data.0.business.users.0.email', $staff->email)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/superadmin/permissions')
            ->assertOk()
            ->assertJsonStructure(['data' => ['modules']]);
        $this->getJson('/api/v1/superadmin/permissions/users?search='.$businessOwner->email)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $businessOwner->id, 'email' => $businessOwner->email])
            ->assertJsonFragment(['vehicles.delete']);
        $this->getJson("/api/v1/superadmin/permissions/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.email', $staff->email);
        $ownerPermissions = $businessOwner->getDirectPermissions()->pluck('name')->reject(fn ($permission) => $permission === 'vehicles.delete')->values()->all();
        $this->putJson("/api/v1/superadmin/permissions/users/{$businessOwner->id}", [
            'permissions' => $ownerPermissions,
        ])->assertOk();
        $this->assertFalse($businessOwner->fresh()->can('vehicles.delete'));
        $this->assertTrue($staff->fresh()->can('vehicles.view'));
        $this->putJson('/api/v1/superadmin/permissions/apply-to-role', [
            'role' => 'staff',
            'permissions' => ['dashboard.view'],
        ])->assertOk()
            ->assertJsonPath('data.updated_users', 1);
        $this->assertSame(['dashboard.view'], $staff->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertTrue($businessOwner->fresh()->can('vehicles.view'));
        $this->postJson("/api/v1/superadmin/users/{$staff->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.user.id', $staff->id)
            ->assertJsonFragment(['dashboard.view'])
            ->assertJsonStructure(['data' => ['token']]);
        $this->putJson("/api/v1/superadmin/users/{$businessOwner->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
        $this->postJson("/api/v1/superadmin/users/{$businessOwner->id}/impersonate")
            ->assertForbidden();

        Sanctum::actingAs($businessOwner);
        $this->getJson('/api/v1/superadmin/dashboard')->assertForbidden();
        $this->postJson("/api/v1/superadmin/users/{$staff->id}/impersonate")->assertForbidden();
    }

    public function test_super_admin_can_create_a_business_owner(): void
    {
        $superAdmin = User::create([
            'business_id' => null,
            'name' => 'Platform Admin',
            'email' => 'owner-creator@vehiclehub.test',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/v1/superadmin/owners', [
            'business_name' => 'North Fleet',
            'owner_name' => 'North Owner',
            'email' => 'owner@north.test',
            'phone' => '09170000000',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'subscription_plan' => 'business',
            'subscription_status' => 'active',
            'vehicle_limit_override' => 12,
            'plan_ends_at' => now()->addYear()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.owner.role', 'owner')
            ->assertJsonPath('data.business.subscription.vehicle_limit', 12)
            ->assertJsonPath('data.business.subscription.default_vehicle_limit', 30)
            ->assertJsonPath('data.business.subscription.has_custom_vehicle_limit', true);

        $this->assertDatabaseHas('businesses', [
            'name' => 'North Fleet',
            'subscription_plan' => 'business',
            'subscription_status' => 'active',
            'vehicle_limit_override' => 12,
        ]);
        $owner = User::where('email', 'owner@north.test')->firstOrFail();
        $this->assertTrue($owner->can('vehicles.create'));
    }

    public function test_updating_a_plan_propagates_its_vehicle_limit_and_preserves_higher_overrides(): void
    {
        $superAdmin = User::create([
            'business_id' => null,
            'name' => 'Platform Admin',
            'email' => 'plan-offering-admin@vehiclehub.test',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        $inherited = Business::create([
            'name' => 'Inherited Limit',
            'slug' => 'inherited-limit',
            'email' => 'inherited-limit@example.com',
            'subscription_plan' => 'starter',
        ]);
        $higherOverride = Business::create([
            'name' => 'Higher Override',
            'slug' => 'higher-override',
            'email' => 'higher-override@example.com',
            'subscription_plan' => 'starter',
            'vehicle_limit_override' => 20,
        ]);
        $lowerOverride = Business::create([
            'name' => 'Lower Override',
            'slug' => 'lower-override',
            'email' => 'lower-override@example.com',
            'subscription_plan' => 'starter',
            'vehicle_limit_override' => 4,
        ]);
        $offering = SubscriptionPlanOffering::where('plan', 'starter')
            ->where('duration_months', 1)
            ->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/v1/superadmin/plan-offerings/{$offering->id}", [
            'plan' => 'starter',
            'name' => 'Starter',
            'duration_months' => 1,
            'price' => 1500,
            'vehicle_limit' => 10,
            'details' => 'Complete vehicle management for small fleets.',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.vehicle_limit', 10);

        $this->assertSame(10, SubscriptionPlanOffering::where('plan', 'starter')->distinct()->value('vehicle_limit'));
        $this->assertNull($inherited->fresh()->vehicle_limit_override);
        $this->assertSame(20, $higherOverride->fresh()->vehicle_limit_override);
        $this->assertNull($lowerOverride->fresh()->vehicle_limit_override);
        $this->assertSame(10, SubscriptionPlans::vehicleLimit($inherited->fresh()));
        $this->assertSame(10, SubscriptionPlans::vehicleLimit($lowerOverride->fresh()));
    }

    public function test_super_admin_can_update_the_trial_vehicle_limit(): void
    {
        $superAdmin = User::create([
            'business_id' => null,
            'name' => 'Platform Admin',
            'email' => 'trial-plan-admin@vehiclehub.test',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        $trialBusiness = Business::create([
            'name' => 'Trial Limit',
            'slug' => 'trial-limit',
            'email' => 'trial-limit@example.com',
            'subscription_plan' => 'trial',
        ]);
        Sanctum::actingAs($superAdmin);

        $trialOffering = $this->getJson('/api/v1/superadmin/plan-offerings')
            ->assertOk()
            ->assertJsonFragment([
                'plan' => 'trial',
                'name' => 'Free Trial',
                'vehicle_limit' => 10,
            ])
            ->json('data.0');

        $this->putJson("/api/v1/superadmin/plan-offerings/{$trialOffering['id']}", [
            'plan' => 'trial',
            'name' => 'Free Trial',
            'duration_months' => 1,
            'price' => 0,
            'vehicle_limit' => 15,
            'details' => 'Explore Vehicle Hub before choosing a paid subscription.',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.vehicle_limit', 15);

        $this->assertSame(15, SubscriptionPlans::defaultVehicleLimit('trial'));
        $this->assertSame(15, SubscriptionPlans::vehicleLimit($trialBusiness->fresh()));
    }

    public function test_owner_plan_offerings_do_not_include_trial_for_purchase(): void
    {
        $owner = $this->user('owner-plan-offerings');
        Sanctum::actingAs($owner);

        $plans = $this->getJson('/api/v1/plan-offerings')
            ->assertOk()
            ->json('data');

        $this->assertNotContains('trial', array_column($plans, 'plan'));
        $this->assertContains('starter', array_column($plans, 'plan'));
    }

    public function test_unpaid_plan_transactions_expire_after_three_days(): void
    {
        $owner = $this->user('expired-plan-payment');
        $method = PaymentMethod::create([
            'name' => 'GCash',
            'account_name' => 'Vehicle Hub',
            'account_number' => '09170000000',
        ]);
        $transaction = PlanTransaction::create([
            'business_id' => $owner->business_id,
            'created_by' => $owner->id,
            'transaction_type' => 'purchase',
            'from_plan' => 'trial',
            'plan' => 'business',
            'duration_months' => 1,
            'original_amount' => 2999,
            'credit_amount' => 0,
            'amount' => 2999,
            'currency' => 'PHP',
            'payment_method_id' => $method->id,
            'payment_method' => $method->name,
            'reference' => 'EXPIRED-PLAN-PAYMENT',
            'paid_at' => null,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => 'processing',
            'payment_status' => 'not_paid',
        ]);
        $transaction->forceFill([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->save();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/plan-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $transaction->id)
            ->assertJsonPath('data.0.payment_status', 'expired')
            ->assertJsonPath('data.0.status', 'expired');

        $this->assertDatabaseHas('plan_transactions', [
            'id' => $transaction->id,
            'payment_status' => 'expired',
            'status' => 'expired',
        ]);
    }

    public function test_business_status_is_derived_from_the_plan_end_date(): void
    {
        $owner = $this->user('reactive-plan-status');
        $superAdmin = User::create([
            'business_id' => null,
            'name' => 'Platform Admin',
            'email' => 'plan-status-admin@vehiclehub.test',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);

        $this->putJson('/api/v1/superadmin/businesses/'.$owner->business_id, [
            'plan_ends_at' => now()->subDay()->toDateString(),
            'subscription_status' => 'active',
        ])->assertOk()->assertJsonPath('data.subscription_status', 'past_due');

        $this->putJson('/api/v1/superadmin/businesses/'.$owner->business_id, [
            'plan_ends_at' => now()->toDateString(),
            'subscription_status' => 'past_due',
        ])->assertOk()->assertJsonPath('data.subscription_status', 'active');

        $this->putJson('/api/v1/superadmin/businesses/'.$owner->business_id, [
            'plan_ends_at' => now()->addMonth()->toDateString(),
        ])->assertOk()->assertJsonPath('data.subscription_status', 'active');
    }

    public function test_super_admin_can_disable_and_reactivate_a_business(): void
    {
        $owner = $this->user('business-access');
        $owner->createToken('existing-session');
        $superAdmin = User::create([
            'name' => 'Platform Admin',
            'email' => 'business-access-admin@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);

        $this->putJson('/api/v1/superadmin/businesses/'.$owner->business_id, [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->putJson('/api/v1/superadmin/businesses/'.$owner->business_id, [
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_business_users_and_super_admin_share_a_support_conversation(): void
    {
        $owner = $this->user('support-business');
        $owner->business->update(['plan_ends_at' => now()->subDay()]);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/support/messages', ['message' => 'Please reactivate our account.'])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'tenant');

        $superAdmin = User::create([
            'business_id' => null,
            'name' => 'Support Admin',
            'email' => 'support-admin@vehiclehub.test',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/v1/superadmin/support/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'support-business')
            ->assertJsonPath('data.0.unread_support_count', 1);
        $this->postJson('/api/v1/superadmin/support/businesses/'.$owner->business_id, [
            'message' => 'We are reviewing your renewal.',
        ])->assertCreated()->assertJsonPath('data.sender_type', 'super_admin');

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/support/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/support/messages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.message', 'We are reviewing your renewal.');
        $this->getJson('/api/v1/support/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
        $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'PLAN_ENDED');
    }

    public function test_guest_support_token_restores_only_its_own_conversation(): void
    {
        $created = $this->postJson('/api/v1/guest-support/conversations', [
            'name' => 'Website Visitor',
            'email' => 'visitor@example.com',
        ])->assertCreated();
        $token = $created->json('data.token');

        $this->withHeader('X-Support-Token', $token)
            ->postJson('/api/v1/guest-support/messages', ['message' => 'I need help before signing in.'])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'guest');

        $this->withHeader('X-Support-Token', $token)
            ->getJson('/api/v1/guest-support/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'I need help before signing in.');

        $this->withHeader('X-Support-Token', str_repeat('x', 64))
            ->getJson('/api/v1/guest-support/messages')
            ->assertNotFound();

        $superAdmin = User::create([
            'name' => 'Guest Support Admin',
            'email' => 'guest-support-admin@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/v1/superadmin/support/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.conversation_type', 'guest')
            ->assertJsonPath('data.0.name', 'Website Visitor');
    }

    public function test_support_history_loads_ten_latest_messages_then_older_messages(): void
    {
        $owner = $this->user('support-history');
        foreach (range(1, 15) as $index) {
            SupportMessage::create([
                'business_id' => $owner->business_id,
                'user_id' => $owner->id,
                'sender_type' => 'tenant',
                'message' => "Message {$index}",
            ]);
        }
        Sanctum::actingAs($owner);

        $latest = $this->getJson('/api/v1/support/messages')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.message', 'Message 6')
            ->assertJsonPath('data.9.message', 'Message 15')
            ->assertJsonPath('meta.has_more', true);
        $firstId = $latest->json('data.0.id');

        $this->getJson('/api/v1/support/messages?before_id='.$firstId)
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.message', 'Message 1')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_support_attachments_are_private_to_the_owner_and_super_admin(): void
    {
        Storage::fake('local');
        $owner = $this->user('support-attachment');
        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/v1/support/messages', [
            'attachment' => UploadedFile::fake()->create('renewal.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.attachment_name', 'renewal.pdf')
            ->assertJsonMissingPath('data.attachment_path');
        $messageId = $response->json('data.id');
        $stored = SupportMessage::findOrFail($messageId);
        Storage::disk('local')->assertExists($stored->getRawOriginal('attachment_path'));
        $this->get('/api/v1/support/attachments/'.$messageId)->assertOk();

        $this->postJson('/api/v1/support/messages', [
            'attachment' => UploadedFile::fake()->create('proposal.docx', 100, 'application/zip'),
        ])->assertCreated()->assertJsonPath('data.attachment_name', 'proposal.docx');

        $otherOwner = $this->user('other-support-attachment');
        Sanctum::actingAs($otherOwner);
        $this->get('/api/v1/support/attachments/'.$messageId)->assertForbidden();
    }

    public function test_super_admin_can_create_support_message_templates(): void
    {
        $superAdmin = User::create([
            'name' => 'Support Admin',
            'email' => 'support-admin@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/superadmin/support/templates')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->postJson('/api/v1/superadmin/support/templates', [
            'title' => 'Follow up',
            'message' => 'We are following up on your support request.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Follow up');

        $this->assertDatabaseHas('support_templates', [
            'title' => 'Follow up',
            'message' => 'We are following up on your support request.',
        ]);

        $templateId = $this->postJson('/api/v1/superadmin/support/templates', [
            'title' => 'Editable reply',
            'message' => 'Original message.',
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/superadmin/support/templates/'.$templateId, [
            'title' => 'Updated reply',
            'message' => 'Updated message.',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated reply')
            ->assertJsonPath('data.message', 'Updated message.');

        $owner = $this->user('template-owner');
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/superadmin/support/templates', [
            'title' => 'Forbidden',
            'message' => 'Owners cannot create templates.',
        ])->assertForbidden();
    }

    public function test_document_creation_ignores_client_business_id(): void
    {
        $a = $this->user('docs-a');
        $b = $this->user('docs-b');
        $v = $this->vehicle($a, 'DOC-1');
        Sanctum::actingAs($a);
        $this->postJson("/api/v1/vehicles/$v->id/documents", ['business_id' => $b->business_id, 'document_type' => 'Registration', 'expiration_date' => now()->addMonth()->toDateString()])->assertCreated();
        $this->assertDatabaseHas('vehicle_documents', ['business_id' => $a->business_id, 'vehicle_id' => $v->id]);
    }

    public function test_dashboard_handles_overdue_unsigned_mileage_without_subtraction(): void
    {
        $user = $this->user('dashboard');
        $vehicle = $this->vehicle($user, 'DASH-1');

        MaintenanceSchedule::withoutGlobalScopes()->create([
            'business_id' => $user->business_id,
            'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'General Maintenance Schedule',
            'interval_type' => 'mileage',
            'next_service_mileage' => 900,
            'reminder_km' => 2000,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.maintenance.total', 1)
            ->assertJsonPath('data.maintenance.upcoming', 0)
            ->assertJsonPath('data.maintenance.overdue', 1);
    }

    public function test_expense_report_keeps_detail_order_out_of_grouped_summary(): void
    {
        $user = $this->user('reports');
        $vehicle = $this->vehicle($user, 'REPORT-1');

        foreach ([['Fuel', 1000], ['Maintenance', 2500]] as [$category, $amount]) {
            VehicleExpense::withoutGlobalScopes()->create([
                'business_id' => $user->business_id,
                'vehicle_id' => $vehicle->id,
                'category' => $category,
                'amount' => $amount,
                'expense_date' => now()->toDateString(),
                'recorded_by' => $user->id,
            ]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/expenses?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonCount(2, 'data.summary');
    }

    public function test_vehicle_operation_lists_only_return_the_authenticated_tenant_records(): void
    {
        $user = $this->user('operations-a');
        $otherUser = $this->user('operations-b');
        $vehicle = $this->vehicle($user, 'OPS-A');
        $otherVehicle = $this->vehicle($otherUser, 'OPS-B');

        foreach ([[$user, $vehicle], [$otherUser, $otherVehicle]] as [$owner, $ownedVehicle]) {
            MaintenanceSchedule::withoutGlobalScopes()->create([
                'business_id' => $owner->business_id,
                'vehicle_id' => $ownedVehicle->id,
                'maintenance_type' => 'General Maintenance Schedule',
                'interval_type' => 'mileage',
                'next_service_mileage' => 1500,
                'reminder_km' => 1000,
            ]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/maintenance?filter=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle.plate_number', 'OPS-A');
    }

    public function test_upcoming_maintenance_filter_excludes_schedules_overdue_by_date_or_mileage(): void
    {
        $owner = $this->user('maintenance-filter');
        $vehicle = $this->vehicle($owner, 'FILTER-1');
        $vehicle->update(['current_mileage' => 5000]);

        MaintenanceSchedule::withoutGlobalScopes()->create([
            'business_id' => $owner->business_id,
            'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'Valid Upcoming',
            'interval_type' => 'both',
            'next_service_date' => now()->addDays(10),
            'next_service_mileage' => 5500,
            'reminder_km' => 1000,
        ]);
        MaintenanceSchedule::withoutGlobalScopes()->create([
            'business_id' => $owner->business_id,
            'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'Mileage Overdue',
            'interval_type' => 'both',
            'next_service_date' => now()->addDays(10),
            'next_service_mileage' => 4500,
            'reminder_km' => 1000,
        ]);
        MaintenanceSchedule::withoutGlobalScopes()->create([
            'business_id' => $owner->business_id,
            'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'Date Overdue',
            'interval_type' => 'both',
            'next_service_date' => now()->subDay(),
            'next_service_mileage' => 5500,
            'reminder_km' => 1000,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/maintenance?filter=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.maintenance_type', 'Valid Upcoming')
            ->assertJsonPath('data.0.due_status', 'upcoming');
    }

    public function test_vehicle_document_attachment_is_stored_and_tenant_protected(): void
    {
        Storage::fake('local');
        $user = $this->user('files-a');
        $otherUser = $this->user('files-b');
        $vehicle = $this->vehicle($user, 'FILE-A');
        Sanctum::actingAs($user);

        $response = $this->post("/api/v1/vehicles/{$vehicle->id}/documents", [
            'document_type' => 'Registration',
            'expiration_date' => now()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('registration.pdf', 250, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $response->json('data.file_path');
        Storage::disk('local')->assertExists($path);
        $documentId = $response->json('data.id');

        $this->get("/api/v1/documents/{$documentId}/download")->assertOk();

        Sanctum::actingAs($otherUser);
        $this->get("/api/v1/documents/{$documentId}/download")->assertNotFound();
    }

    public function test_odometer_photo_can_be_uploaded_and_is_tenant_protected(): void
    {
        Storage::fake('local');
        $user = $this->user('photos-a');
        $otherUser = $this->user('photos-b');
        $vehicle = $this->vehicle($user, 'PHOTO-A');
        Sanctum::actingAs($user);

        $response = $this->post("/api/v1/vehicles/{$vehicle->id}/mileage", [
            'mileage' => 1250,
            'notes' => 'Odometer verified',
            'photo' => UploadedFile::fake()->create('odometer.jpg', 300, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $response->json('data.photo');
        Storage::disk('local')->assertExists($path);
        $logId = $response->json('data.id');

        $this->get("/api/v1/mileage/{$logId}/photo")->assertOk();
        $this->getJson("/api/v1/vehicles/{$vehicle->id}/mileage")
            ->assertOk()
            ->assertJsonPath('data.0.recorder.name', $user->name);

        Sanctum::actingAs($otherUser);
        $this->get("/api/v1/mileage/{$logId}/photo")->assertNotFound();
    }
}
