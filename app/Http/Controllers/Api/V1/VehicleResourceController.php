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
use App\Services\ExpenseSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        if (in_array($resource, ['expenses', 'fuel'])) {
            $q->with('recorder:id,name');
        }
        foreach (['status', 'category', 'document_type', 'priority'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->$f);
            }
        }

        if ($resource === 'expenses') {
            $filters = $r->validate([
                'record_id' => 'nullable|integer|min:1',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'vendor' => 'nullable|string|max:150',
                'recorded_by' => 'nullable|string|max:150',
                'sort_by' => 'nullable|in:category,amount,expense_date,vendor,recorded_by_name',
                'sort_direction' => 'nullable|in:asc,desc',
            ]);
            $q->when(isset($filters['record_id']), fn ($query) => $query->whereKey($filters['record_id']))
                ->when(isset($filters['from']), fn ($query) => $query->whereDate('expense_date', '>=', $filters['from']))
                ->when(isset($filters['to']), fn ($query) => $query->whereDate('expense_date', '<=', $filters['to']))
                ->when(isset($filters['vendor']), fn ($query) => $query->where('vendor', 'like', '%'.$filters['vendor'].'%'))
                ->when(isset($filters['recorded_by']), fn ($query) => $query->whereHas('recorder', fn ($recorder) => $recorder->where('name', 'like', '%'.$filters['recorded_by'].'%')));

            $totalAmount = (float) (clone $q)->sum('amount');

            $sortBy = $filters['sort_by'] ?? 'expense_date';
            $direction = $filters['sort_direction'] ?? 'desc';
            if ($sortBy === 'recorded_by_name') {
                $q->leftJoin('users as recorders', 'recorders.id', '=', 'vehicle_expenses.recorded_by')
                    ->select('vehicle_expenses.*')
                    ->orderBy('recorders.name', $direction);
            } else {
                $q->orderBy($sortBy, $direction);
            }

            return $this->paginated(
                $q->paginate(min((int) $r->input('per_page', 20), 100)),
                ['total_amount' => $totalAmount],
            );
        }

        if ($resource === 'fuel') {
            $filters = $r->validate([
                'record_id' => 'nullable|integer|min:1',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'recorded_by' => 'nullable|string|max:150',
                'sort_by' => 'nullable|in:recorded_by_name,fuel_date,mileage,liters,price_per_liter,total_amount',
                'sort_direction' => 'nullable|in:asc,desc',
            ]);
            $q->when(isset($filters['record_id']), fn ($query) => $query->whereKey($filters['record_id']))
                ->when(isset($filters['from']), fn ($query) => $query->whereDate('fuel_date', '>=', $filters['from']))
                ->when(isset($filters['to']), fn ($query) => $query->whereDate('fuel_date', '<=', $filters['to']))
                ->when(isset($filters['recorded_by']), fn ($query) => $query->whereHas('recorder', fn ($recorder) => $recorder->where('name', 'like', '%'.$filters['recorded_by'].'%')));

            $totalAmount = (float) (clone $q)->sum('total_amount');

            $sortBy = $filters['sort_by'] ?? 'fuel_date';
            $direction = $filters['sort_direction'] ?? 'desc';
            if ($sortBy === 'recorded_by_name') {
                $q->leftJoin('users as recorders', 'recorders.id', '=', 'fuel_logs.recorded_by')
                    ->select('fuel_logs.*')
                    ->orderBy('recorders.name', $direction);
            } else {
                $q->orderBy($sortBy, $direction);
            }

            return $this->paginated(
                $q->paginate(min((int) $r->input('per_page', 20), 100)),
                ['total_amount' => $totalAmount],
            );
        }

        return $this->paginated($q->latest()->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function showNested(Request $request, Vehicle $vehicle, string $resource, int $id)
    {
        $this->authorizeAction($request, $resource, 'view');
        $query = $this->class($resource)::where('vehicle_id', $vehicle->id);

        if (in_array($resource, ['expenses', 'fuel'], true)) {
            $query->with('recorder:id,name');
        }

        return $this->ok($query->findOrFail($id));
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

        return DB::transaction(fn () => $this->createResource($r, $resource, $vehicle));
    }

    public function rootIndex(Request $r, string $resource)
    {
        $this->authorizeAction($r, $resource, 'view');
        $query = $this->class($resource)::query();
        if ($resource === 'assignments') {
            $query->with(['vehicle:id,brand,model,plate_number,vehicle_code', 'driver:id,name,employee_number']);
        }
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

    public function driverFile(Request $request, Driver $driver, string $type)
    {
        $this->authorizeAction($request, 'drivers', 'view');
        abort_unless(in_array($type, ['driver', 'license'], true), 404);
        $path = $type === 'driver' ? $driver->profile_photo : $driver->license_photo;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, basename($path));
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
        if ($resource === 'drivers') {
            $data['employee_number'] = ($data['employee_number'] ?? null) ?: $this->generateEmployeeId($r);
            if ($r->hasFile('driver_photo')) {
                $data['profile_photo'] = $r->file('driver_photo')->store("businesses/{$r->user()->business_id}/drivers/photos");
            }
            if ($r->hasFile('license_photo')) {
                $data['license_photo'] = $r->file('license_photo')->store("businesses/{$r->user()->business_id}/drivers/licenses");
            }
        }
        if ($resource === 'schedules' && $vehicle) {
            $data = $this->calculateScheduleDueValues($data, $vehicle);
        }
        unset($data['file'], $data['driver_photo']);
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
        if ($resource === 'fuel') {
            app(ExpenseSyncService::class)->fuel($model);
        }
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
        $data = $this->validated($r, $resource, true, $m);
        if ($resource === 'drivers') {
            if ($r->hasFile('driver_photo')) {
                $oldPath = $m->profile_photo;
                $data['profile_photo'] = $r->file('driver_photo')->store("businesses/{$r->user()->business_id}/drivers/photos");
                if ($oldPath) {
                    Storage::delete($oldPath);
                }
            }
            if ($r->hasFile('license_photo')) {
                $oldPath = $m->license_photo;
                $data['license_photo'] = $r->file('license_photo')->store("businesses/{$r->user()->business_id}/drivers/licenses");
                if ($oldPath) {
                    Storage::delete($oldPath);
                }
            }
            unset($data['driver_photo'], $data['license_photo']);
        }
        $m->update($data);
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

        if ($resource === 'schedules') {
            $data = $this->calculateScheduleDueValues($data, $vehicle, $model);
        }

        if ($resource === 'expenses' && ($model->maintenance_record_id || $model->fuel_log_id)) {
            throw ValidationException::withMessages([
                'expense' => 'Generated expenses must be updated from their maintenance or fuel record.',
            ]);
        }

        DB::transaction(function () use ($model, $data, $resource, $old) {
            $model->update($data);
            if ($resource === 'fuel') {
                app(ExpenseSyncService::class)->fuel($model);
            }
            app(AuditService::class)->record("{$resource}.updated", $model, $old);
        });

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
        if ($resource === 'expenses' && ($model->maintenance_record_id || $model->fuel_log_id)) {
            throw ValidationException::withMessages([
                'expense' => 'Generated expenses must be deleted from their maintenance or fuel record.',
            ]);
        }
        DB::transaction(function () use ($model, $resource) {
            if ($resource === 'fuel') {
                app(ExpenseSyncService::class)->deleteForFuel($model);
            }
            $model->delete();
            app(AuditService::class)->record("{$resource}.deleted", $model);
        });

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

    private function validated(Request $r, string $t, bool $partial = false, ?object $model = null): array
    {
        $p = $partial ? 'sometimes' : 'required';
        $rules = match ($t) {
            'documents' => ['document_type' => "$p|string", 'document_number' => 'nullable|string', 'issue_date' => 'nullable|date', 'expiration_date' => 'nullable|date', 'file_path' => 'nullable|string', 'notes' => 'nullable|string', 'status' => 'nullable|string'],'expenses' => ['category' => "$p|string", 'amount' => "$p|numeric|min:0", 'expense_date' => "$p|date", 'vendor' => 'nullable|string', 'description' => 'nullable|string', 'receipt_path' => 'nullable|string'],'issues' => ['title' => "$p|string", 'description' => "$p|string", 'priority' => 'nullable|in:low,medium,high,critical', 'category' => "$p|string", 'status' => 'nullable|in:reported,for_inspection,approved,in_repair,completed,cancelled', 'mileage' => 'nullable|integer', 'assigned_to' => 'nullable|integer', 'assigned_to_name' => 'nullable|string|max:150', 'resolution_notes' => 'nullable|string', 'estimated_cost' => 'nullable|numeric', 'actual_cost' => 'nullable|numeric'],'fuel' => ['fuel_date' => "$p|date", 'mileage' => "$p|integer", 'liters' => "$p|numeric|min:0.001", 'price_per_liter' => "$p|numeric|min:0", 'fuel_type' => 'nullable|string', 'station' => 'nullable|string', 'receipt_path' => 'nullable|string', 'notes' => 'nullable|string'],'schedules' => ['maintenance_type' => "$p|string", 'interval_type' => "$p|in:mileage,date,both", 'interval_km' => 'nullable|integer', 'interval_months' => 'nullable|integer', 'last_service_mileage' => 'nullable|integer', 'last_service_date' => 'nullable|date', 'next_service_mileage' => 'nullable|integer', 'next_service_date' => 'nullable|date', 'reminder_km' => 'nullable|integer', 'reminder_days' => 'nullable|integer', 'status' => 'nullable|string'],'drivers' => ['user_id' => 'nullable|integer', 'employee_number' => 'nullable|string', 'name' => "$p|string", 'email' => 'nullable|email', 'phone' => 'nullable|string', 'license_number' => 'nullable|string', 'license_type' => 'nullable|string', 'license_expiration' => 'nullable|date', 'date_hired' => 'nullable|date', 'status' => 'nullable|string'],'assignments' => ['vehicle_id' => "$p|integer", 'driver_id' => "$p|integer", 'assigned_at' => 'nullable|date', 'returned_at' => 'nullable|date', 'status' => 'nullable|in:active,completed,cancelled', 'notes' => 'nullable|string'],default => []
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

        if ($t === 'drivers') {
            $rules['employee_number'] = ['nullable', 'string', 'max:100', Rule::unique('drivers')->where('business_id', $r->user()->business_id)->ignore($model?->id)];
            $rules['status'] = ['nullable', Rule::in(['active', 'inactive'])];
            $rules['driver_photo'] = 'nullable|file|mimes:jpg,jpeg,png|max:10240';
            $rules['license_photo'] = 'nullable|file|mimes:jpg,jpeg,png|max:10240';
        }

        return $r->validate($rules);
    }

    private function generateEmployeeId(Request $request): string
    {
        $prefix = Str::upper(Str::of($request->user()->business->name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '')->value()) ?: 'BUSINESS';

        do {
            $employeeId = $prefix.'-'.Str::upper(Str::random(6));
        } while (Driver::withTrashed()->where('employee_number', $employeeId)->exists());

        return $employeeId;
    }

    private function calculateScheduleDueValues(array $data, Vehicle $vehicle, ?MaintenanceSchedule $schedule = null): array
    {
        $intervalType = $data['interval_type'] ?? $schedule?->interval_type;
        $intervalKm = $data['interval_km'] ?? $schedule?->interval_km;
        $intervalMonths = $data['interval_months'] ?? $schedule?->interval_months;
        $lastServiceMileage = $data['last_service_mileage'] ?? $schedule?->last_service_mileage;
        $lastServiceDate = $data['last_service_date'] ?? $schedule?->last_service_date;
        $usesMileage = in_array($intervalType, ['mileage', 'both'], true);
        $usesDate = in_array($intervalType, ['date', 'both'], true);

        $data['next_service_mileage'] = $usesMileage && $intervalKm
            ? (int) ($lastServiceMileage ?? $vehicle->current_mileage ?? 0) + (int) $intervalKm
            : null;
        $data['next_service_date'] = $usesDate && $intervalMonths
            ? Carbon::parse($lastServiceDate ?? $schedule?->created_at ?? now())
                ->addMonths((int) $intervalMonths)
                ->toDateString()
            : null;

        return $data;
    }
}
