<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
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

    public function test_role_permissions_control_crud_and_are_returned_by_me(): void
    {
        $owner = $this->user('permission-owner');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonFragment(['vehicles.create']);

        Role::findByName('owner', 'web')->revokePermissionTo('vehicles.create');

        $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'DENIED-1',
            'brand' => 'Toyota',
            'model' => 'Vios',
            'vehicle_type' => 'Car',
        ])->assertForbidden();
    }

    public function test_login_returns_effective_role_permissions(): void
    {
        $owner = $this->user('login-permissions');

        $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.permissions.0', 'dashboard.view')
            ->assertJsonFragment(['vehicles.view']);
    }

    public function test_registration_creates_business_owner_trial_and_token(): void
    {
        $this->postJson('/api/v1/auth/register', ['business_name' => 'Acme Vehicle', 'owner_name' => 'Joel', 'email' => 'owner@acme.test', 'phone' => '09170000000', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertCreated()->assertJsonPath('data.user.role', 'owner')->assertJsonPath('data.business.subscription_status', 'trial')->assertJsonStructure(['data' => ['token']]);
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

    public function test_vehicle_code_is_unique_inside_business(): void
    {
        $owner = $this->user('vehicle-codes');
        $this->vehicle($owner, 'CODE-1')->update(['vehicle_code' => 'TRK-0001']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'CODE-2',
            'vehicle_code' => 'TRK-0001',
            'brand' => 'Isuzu',
            'model' => 'N-Series',
            'vehicle_type' => 'Truck',
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_code');
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
        $v = $this->vehicle($u, 'PMS-1');
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/vehicles/$v->id/maintenance", ['service_date' => now()->toDateString(), 'mileage' => 1000, 'maintenance_type' => 'General PMS', 'service_provider' => 'Vehicle Hub Workshop', 'labor_cost' => 100, 'parts_cost' => 200, 'other_cost' => 50])->assertCreated()->assertJsonPath('data.total_cost', '350.00');
        $this->getJson("/api/v1/vehicles/$v->id/maintenance")
            ->assertOk()
            ->assertJsonPath('data.0.performer.name', $u->name)
            ->assertJsonPath('data.0.service_provider', 'Vehicle Hub Workshop');
    }

    public function test_maintenance_can_be_updated_and_deleted(): void
    {
        $user = $this->user('maint-crud');
        $vehicle = $this->vehicle($user, 'PMS-CRUD');
        Sanctum::actingAs($user);

        $id = $this->postJson("/api/v1/vehicles/{$vehicle->id}/maintenance", [
            'service_date' => now()->toDateString(),
            'mileage' => 1000,
            'maintenance_type' => 'Oil change',
            'labor_cost' => 100,
            'parts_cost' => 200,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/vehicles/{$vehicle->id}/maintenance/{$id}", [
            'labor_cost' => 150,
        ])->assertOk()->assertJsonPath('data.total_cost', '350.00');

        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}/maintenance/{$id}")->assertOk();
        $this->assertSoftDeleted('maintenance_records', ['id' => $id]);
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
            'assigned_to' => $reporter->id,
        ])->assertCreated();

        $this->getJson("/api/v1/vehicles/{$vehicle->id}/issues")
            ->assertOk()
            ->assertJsonPath('data.0.reporter.name', $reporter->name)
            ->assertJsonPath('data.0.assignee.name', $reporter->name);
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

    public function test_owner_can_manage_up_to_three_staff_with_staff_as_default_role(): void
    {
        $owner = $this->user('staff-limit');
        Sanctum::actingAs($owner);

        for ($index = 1; $index <= 3; $index++) {
            $response = $this->postJson('/api/v1/staff', [
                'name' => "Staff {$index}",
                'email' => "staff{$index}@limit.test",
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertCreated();
            $response->assertJsonPath('data.role', 'staff');
        }

        $this->postJson('/api/v1/staff', [
            'name' => 'Fourth Staff',
            'email' => 'staff4@limit.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.created']);
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
        $this->putJson("/api/v1/superadmin/users/{$businessOwner->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        Sanctum::actingAs($businessOwner);
        $this->getJson('/api/v1/superadmin/dashboard')->assertForbidden();
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
            'maintenance_type' => 'General PMS',
            'interval_type' => 'mileage',
            'next_service_mileage' => 900,
            'reminder_km' => 2000,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
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
                'maintenance_type' => 'General PMS',
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
