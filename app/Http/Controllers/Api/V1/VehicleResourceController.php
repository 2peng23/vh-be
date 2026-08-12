<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VehicleResourceController extends ApiController
{
    private const MAP = ['documents' => VehicleDocument::class, 'expenses' => VehicleExpense::class, 'issues' => VehicleIssue::class, 'fuel' => FuelLog::class, 'schedules' => MaintenanceSchedule::class, 'drivers' => Driver::class, 'assignments' => VehicleAssignment::class];

    public function index(Request $r, Vehicle $vehicle, string $resource)
    {
        $this->authorizeAction($r, $resource, 'view');
        $q = $this->class($resource)::where('vehicle_id', $vehicle->id);
        if ($resource === 'issues') {
            $q->with(['reporter:id,name', 'assignee:id,name']);
        }
        foreach (['status', 'category', 'document_type', 'priority'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->$f);
            }
        }

        return $this->paginated($q->latest()->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function assignees(Request $request)
    {
        abort_unless($request->user()->can('issues.create') || $request->user()->can('issues.update'), 403);

        return $this->ok(User::query()
            ->where('business_id', $request->user()->business_id)
            ->orderBy('name')
            ->get(['id', 'name', 'role']));
    }

    public function store(Request $r, Vehicle $vehicle, string $resource)
    {
        $this->authorizeAction($r, $resource, 'create');

        return $this->createResource($r, $resource, $vehicle);
    }

    public function rootIndex(Request $r, string $resource)
    {
        $this->authorizeAction($r, $resource, 'view');
        $query = $this->class($resource)::query();
        if ($resource === 'drivers' && $r->filled('search')) {
            $search = $r->string('search')->trim()->value();
            $query->where(fn ($driver) => $driver
                ->where('name', 'like', "%{$search}%")
                ->orWhere('employee_number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('license_number', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function rootStore(Request $r, string $resource)
    {
        $this->authorizeAction($r, $resource, 'create');

        return $this->createResource($r, $resource);
    }

    private function createResource(Request $r, string $resource, ?Vehicle $vehicle = null)
    {
        $class = $this->class($resource);
        $data = $this->validated($r, $resource);
        if ($vehicle) {
            $data['vehicle_id'] = $vehicle->id;
        }
        if ($resource === 'documents' && $r->hasFile('file')) {
            $data['file_path'] = $r->file('file')->store(
                "businesses/{$r->user()->business_id}/vehicles/{$vehicle->id}/documents"
            );
        }
        unset($data['file']);
        $columns = (new $class)->getConnection()->getSchemaBuilder()->getColumnListing((new $class)->getTable());
        foreach (['recorded_by', 'reported_by', 'assigned_by'] as $key) {
            if (in_array($key, $columns)) {
                $data[$key] = $r->user()->id;
            }
        }if ($resource === 'issues') {
            $data['reported_at'] ??= now();
        }if ($resource === 'assignments') {
            $data['assigned_at'] ??= now();
        }if ($resource === 'fuel') {
            $data['total_amount'] = $data['liters'] * $data['price_per_liter'];
        }

        $model = $class::create($data);
        app(AuditService::class)->record("{$resource}.created", $model);

        return $this->ok($model, ucfirst($resource).' created.', 201);
    }

    public function download(VehicleDocument $document)
    {
        abort_unless($document->file_path && Storage::exists($document->file_path), 404);

        return Storage::download($document->file_path, basename($document->file_path));
    }

    public function show(Request $request, int $id, string $resource)
    {
        $this->authorizeAction($request, $resource, 'view');

        return $this->ok($this->class($resource)::findOrFail($id));
    }

    public function update(Request $r, int $id, string $resource)
    {
        $this->authorizeAction($r, $resource, 'update');
        $m = $this->class($resource)::findOrFail($id);
        $old = $m->toArray();
        $m->update($this->validated($r, $resource, true));
        app(AuditService::class)->record("{$resource}.updated", $m, $old);

        return $this->ok($m, ucfirst($resource).' updated.');
    }

    public function updateNested(Request $request, Vehicle $vehicle, string $resource, int $id)
    {
        $this->authorizeAction($request, $resource, 'update');
        $model = $this->class($resource)::where('vehicle_id', $vehicle->id)->findOrFail($id);
        $old = $model->toArray();
        $data = $this->validated($request, $resource, true);

        if ($resource === 'documents' && $request->hasFile('file')) {
            $oldPath = $model->file_path;
            $data['file_path'] = $request->file('file')->store(
                "businesses/{$request->user()->business_id}/vehicles/{$vehicle->id}/documents"
            );
            if ($oldPath) {
                Storage::delete($oldPath);
            }
        }
        unset($data['file']);

        if ($resource === 'fuel' && (isset($data['liters']) || isset($data['price_per_liter']))) {
            $data['total_amount'] = ($data['liters'] ?? $model->liters) * ($data['price_per_liter'] ?? $model->price_per_liter);
        }

        $model->update($data);
        app(AuditService::class)->record("{$resource}.updated", $model, $old);

        return $this->ok($model->fresh(), ucfirst($resource).' updated.');
    }

    public function destroy(Request $r, int $id, string $resource)
    {
        $this->authorizeAction($r, $resource, 'delete');
        $model = $this->class($resource)::findOrFail($id);
        $model->delete();
        app(AuditService::class)->record("{$resource}.deleted", $model);

        return $this->ok(null, ucfirst($resource).' archived.');
    }

    public function destroyNested(Request $request, Vehicle $vehicle, string $resource, int $id)
    {
        $this->authorizeAction($request, $resource, 'delete');
        $model = $this->class($resource)::where('vehicle_id', $vehicle->id)->findOrFail($id);
        $model->delete();
        app(AuditService::class)->record("{$resource}.deleted", $model);

        return $this->ok(null, ucfirst($resource).' deleted.');
    }

    private function class(string $r): string
    {
        abort_unless(isset(self::MAP[$r]), 404);

        return self::MAP[$r];
    }

    private function authorizeAction(Request $request, string $resource, string $action): void
    {
        abort_unless($request->user()->can("{$resource}.{$action}"), 403);
    }

    private function validated(Request $r, string $t, bool $partial = false): array
    {
        $p = $partial ? 'sometimes' : 'required';
        $rules = match ($t) {
            'documents' => ['document_type' => "$p|string", 'document_number' => 'nullable|string', 'issue_date' => 'nullable|date', 'expiration_date' => 'nullable|date', 'file_path' => 'nullable|string', 'notes' => 'nullable|string', 'status' => 'nullable|string'],'expenses' => ['category' => "$p|string", 'amount' => "$p|numeric|min:0", 'expense_date' => "$p|date", 'vendor' => 'nullable|string', 'description' => 'nullable|string', 'receipt_path' => 'nullable|string', 'maintenance_record_id' => 'nullable|integer'],'issues' => ['title' => "$p|string", 'description' => "$p|string", 'priority' => 'nullable|in:low,medium,high,critical', 'category' => "$p|string", 'status' => 'nullable|in:reported,for_inspection,approved,in_repair,completed,cancelled', 'mileage' => 'nullable|integer', 'assigned_to' => 'nullable|integer', 'resolution_notes' => 'nullable|string', 'estimated_cost' => 'nullable|numeric', 'actual_cost' => 'nullable|numeric'],'fuel' => ['fuel_date' => "$p|date", 'mileage' => "$p|integer", 'liters' => "$p|numeric|min:0.001", 'price_per_liter' => "$p|numeric|min:0", 'fuel_type' => 'nullable|string', 'station' => 'nullable|string', 'receipt_path' => 'nullable|string', 'notes' => 'nullable|string'],'schedules' => ['maintenance_type' => "$p|string", 'interval_type' => "$p|in:mileage,date,both", 'interval_km' => 'nullable|integer', 'interval_months' => 'nullable|integer', 'last_service_mileage' => 'nullable|integer', 'last_service_date' => 'nullable|date', 'next_service_mileage' => 'nullable|integer', 'next_service_date' => 'nullable|date', 'reminder_km' => 'nullable|integer', 'reminder_days' => 'nullable|integer', 'status' => 'nullable|string'],'drivers' => ['user_id' => 'nullable|integer', 'employee_number' => 'nullable|string', 'name' => "$p|string", 'email' => 'nullable|email', 'phone' => 'nullable|string', 'license_number' => 'nullable|string', 'license_type' => 'nullable|string', 'license_expiration' => 'nullable|date', 'date_hired' => 'nullable|date', 'status' => 'nullable|string'],'assignments' => ['vehicle_id' => "$p|integer", 'driver_id' => "$p|integer", 'assigned_at' => 'nullable|date', 'returned_at' => 'nullable|date', 'status' => 'nullable|in:active,completed,cancelled', 'notes' => 'nullable|string'],default => []
        };

        if ($t === 'issues') {
            $rules['assigned_to'] = [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('business_id', $r->user()->business_id),
            ];
        }

        if ($t === 'documents') {
            unset($rules['file_path']);
            $rules['file'] = 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240';
        }

        return $r->validate($rules);
    }
}
