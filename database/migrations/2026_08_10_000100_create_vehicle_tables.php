<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
    }

    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('plate_number');
            $t->string('vehicle_code')->nullable();
            $t->string('brand');
            $t->string('model');
            $t->string('variant')->nullable();
            $t->unsignedSmallInteger('year')->nullable();
            $t->string('vehicle_type');
            $t->string('color')->nullable();
            $t->string('vin')->nullable();
            $t->string('engine_number')->nullable();
            $t->string('chassis_number')->nullable();
            $t->unsignedBigInteger('current_mileage')->default(0);
            $t->date('acquisition_date')->nullable();
            $t->decimal('acquisition_cost', 14, 2)->nullable();
            $t->string('status')->default('active')->index();
            $t->text('notes')->nullable();
            $t->string('primary_photo')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['business_id', 'plate_number']);
            $t->index(['business_id', 'brand', 'model']);
        });
        Schema::create('drivers', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('employee_number')->nullable();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('license_number')->nullable();
            $t->string('license_type')->nullable();
            $t->date('license_expiration')->nullable()->index();
            $t->date('date_hired')->nullable();
            $t->string('status')->default('active');
            $t->string('profile_photo')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('vehicle_assignments', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_by')->constrained('users');
            $t->timestamp('assigned_at');
            $t->timestamp('returned_at')->nullable();
            $t->string('status')->default('active')->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('mileage_logs', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('recorded_by')->constrained('users');
            $t->unsignedBigInteger('mileage');
            $t->timestamp('recorded_at');
            $t->text('notes')->nullable();
            $t->string('photo')->nullable();
            $t->boolean('is_override')->default(false);
            $t->timestamps();
            $t->index(['business_id', 'vehicle_id', 'recorded_at']);
        });
        Schema::create('maintenance_schedules', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('maintenance_type');
            $t->string('interval_type');
            $t->unsignedInteger('interval_km')->nullable();
            $t->unsignedInteger('interval_months')->nullable();
            $t->unsignedBigInteger('last_service_mileage')->nullable();
            $t->date('last_service_date')->nullable();
            $t->unsignedBigInteger('next_service_mileage')->nullable()->index();
            $t->date('next_service_date')->nullable()->index();
            $t->unsignedInteger('reminder_km')->default(2000);
            $t->unsignedInteger('reminder_days')->default(30);
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('maintenance_records', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('maintenance_schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('service_provider')->nullable();
            $t->date('service_date');
            $t->unsignedBigInteger('mileage');
            $t->string('maintenance_type');
            $t->text('description')->nullable();
            foreach (['labor_cost', 'parts_cost', 'other_cost', 'total_cost'] as $c) {
                $t->decimal($c, 14, 2)->default(0);
            } $t->date('next_service_date')->nullable();
            $t->unsignedBigInteger('next_service_mileage')->nullable();
            $t->string('status')->default('completed');
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['business_id', 'service_date']);
        });
        Schema::create('maintenance_parts', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('maintenance_record_id')->constrained()->cascadeOnDelete();
            $t->string('part_name');
            $t->string('part_number')->nullable();
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_cost', 14, 2)->default(0);
            $t->decimal('total_cost', 14, 2)->default(0);
            $t->string('supplier')->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_documents', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('document_type')->index();
            $t->string('document_number')->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiration_date')->nullable()->index();
            $t->string('file_path')->nullable();
            $t->text('notes')->nullable();
            $t->string('status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('vehicle_expenses', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('category')->index();
            $t->decimal('amount', 14, 2);
            $t->date('expense_date')->index();
            $t->string('vendor')->nullable();
            $t->text('description')->nullable();
            $t->string('receipt_path')->nullable();
            $t->foreignId('recorded_by')->constrained('users');
            $t->foreignId('maintenance_record_id')->nullable()->constrained()->nullOnDelete();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['business_id', 'expense_date']);
        });
        Schema::create('fuel_logs', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('recorded_by')->constrained('users');
            $t->date('fuel_date')->index();
            $t->unsignedBigInteger('mileage');
            $t->decimal('liters', 10, 3);
            $t->decimal('price_per_liter', 10, 2);
            $t->decimal('total_amount', 14, 2);
            $t->string('fuel_type')->nullable();
            $t->string('station')->nullable();
            $t->string('receipt_path')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_issues', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reported_by')->constrained('users');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->text('description');
            $t->string('priority')->default('medium')->index();
            $t->string('category');
            $t->string('status')->default('reported')->index();
            $t->unsignedBigInteger('mileage')->nullable();
            $t->timestamp('reported_at');
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->decimal('estimated_cost', 14, 2)->nullable();
            $t->decimal('actual_cost', 14, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_photos', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('file_path');
            $t->string('caption')->nullable();
            $t->string('photo_type')->default('general');
            $t->timestamps();
        });
        Schema::create('vehicle_issue_attachments', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_issue_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('file_path');
            $t->string('file_type');
            $t->timestamps();
        });
        Schema::create('inspection_templates', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('inspection_template_items', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('inspection_template_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('photo_required')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('vehicle_inspections', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inspection_template_id')->constrained();
            $t->foreignId('inspected_by')->constrained('users');
            $t->timestamp('inspected_at');
            $t->unsignedBigInteger('mileage')->nullable();
            $t->string('overall_status')->default('good');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_inspection_items', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('vehicle_inspection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inspection_template_item_id')->constrained();
            $t->string('status');
            $t->text('notes')->nullable();
            $t->string('photo')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action')->index();
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['business_id', 'entity_type', 'entity_id']);
        });
        Schema::create('reminder_deliveries', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('remindable_type', 150);
            $t->unsignedBigInteger('remindable_id');
            $t->string('kind', 50);
            $t->string('threshold', 30);
            $t->timestamp('sent_at');
            $t->unique(['business_id', 'remindable_type', 'remindable_id', 'kind', 'threshold'], 'reminder_once');
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'reminder_deliveries', 'audit_logs', 'vehicle_inspection_items', 'vehicle_inspections', 'inspection_template_items', 'inspection_templates', 'vehicle_issue_attachments', 'vehicle_photos', 'vehicle_issues', 'fuel_logs', 'vehicle_expenses', 'vehicle_documents', 'maintenance_parts', 'maintenance_records', 'maintenance_schedules', 'mileage_logs', 'vehicle_assignments', 'drivers', 'vehicles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
