<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\VehicleResource\ListVehicleResourceRequest;
use App\Http\Requests\VehicleResource\SaveVehicleResourceRequest;
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
use Illuminate\Validation\ValidationException;

class VehicleResourceController extends ApiController
{
    private const MAP = ['documents' => VehicleDocument::class, 'expenses' => VehicleExpense::class, 'issues' => VehicleIssue::class, 'fuel' => FuelLog::class, 'schedules' => MaintenanceSchedule::class, 'drivers' => Driver::class, 'assignments' => VehicleAssignment::class];

    public function index(ListVehicleResourceRequest $request, Vehicle $vehicle, string $resource)
    {
        $this->authorizeAction($request, $resource, 'view');
        $filters = $request->validated();
        $query = $this->class($resource)::where('vehicle_id', $vehicle->id);
        if ($resource === 'issues') {
            $query->with(['reporter:id,name', 'assignee:id,name']);
        }
        if (in_array($resource, ['expenses', 'fuel'])) {
            $query->with('recorder:id,name');
        }
        foreach (['status', 'category', 'document_type', 'priority'] as $filterKey) {
            if (isset($filters[$filterKey])) {
                $query->where($filterKey, $filters[$filterKey]);
            }
        }

        if ($resource === 'expenses') {
            $query->when(isset($filters['record_id']), fn ($queryBuilder) => $queryBuilder->whereKey($filters['record_id']))
                ->when(isset($filters['from']), fn ($query) => $query->whereDate('expense_date', '>=', $filters['from']))
                ->when(isset($filters['to']), fn ($query) => $query->whereDate('expense_date', '<=', $filters['to']))
                ->when(isset($filters['vendor']), fn ($query) => $query->where('vendor', 'like', '%'.$filters['vendor'].'%'))
                ->when(isset($filters['recorded_by']), fn ($query) => $query->whereHas('recorder', fn ($recorder) => $recorder->where('name', 'like', '%'.$filters['recorded_by'].'%')));

            $totalAmount = (float) (clone $query)->sum('amount');

            $sortBy = $filters['sort_by'] ?? 'expense_date';
            $direction = $filters['sort_direction'] ?? 'desc';
            if ($sortBy === 'recorded_by_name') {
                $query->leftJoin('users as recorders', 'recorders.id', '=', 'vehicle_expenses.recorded_by')
                    ->select('vehicle_expenses.*')
                    ->orderBy('recorders.name', $direction);
            } else {
                $query->orderBy($sortBy, $direction);
            }

            return $this->paginated(
                $query->paginate(min((int) $request->input('per_page', 20), 100)),
                ['total_amount' => $totalAmount],
            );
        }

        if ($resource === 'fuel') {
            $query->when(isset($filters['record_id']), fn ($queryBuilder) => $queryBuilder->whereKey($filters['record_id']))
                ->when(isset($filters['from']), fn ($query) => $query->whereDate('fuel_date', '>=', $filters['from']))
                ->when(isset($filters['to']), fn ($query) => $query->whereDate('fuel_date', '<=', $filters['to']))
                ->when(isset($filters['recorded_by']), fn ($query) => $query->whereHas('recorder', fn ($recorder) => $recorder->where('name', 'like', '%'.$filters['recorded_by'].'%')));

            $totalAmount = (float) (clone $query)->sum('total_amount');

            $sortBy = $filters['sort_by'] ?? 'fuel_date';
            $direction = $filters['sort_direction'] ?? 'desc';
            if ($sortBy === 'recorded_by_name') {
                $query->leftJoin('users as recorders', 'recorders.id', '=', 'fuel_logs.recorded_by')
                    ->select('fuel_logs.*')
                    ->orderBy('recorders.name', $direction);
            } else {
                $query->orderBy($sortBy, $direction);
            }

            return $this->paginated(
                $query->paginate(min((int) $request->input('per_page', 20), 100)),
                ['total_amount' => $totalAmount],
            );
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
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

    public function store(SaveVehicleResourceRequest $request, Vehicle $vehicle, string $resource)
    {
        $this->authorizeAction($request, $resource, 'create');

        return DB::transaction(fn () => $this->createResource($request, $resource, $vehicle));
    }

    public function rootIndex(ListVehicleResourceRequest $request, string $resource)
    {
        $this->authorizeAction($request, $resource, 'view');
        $query = $this->class($resource)::query();
        if ($resource === 'assignments') {
            $query->with(['vehicle:id,brand,model,plate_number,vehicle_code', 'driver:id,name,employee_number']);
        }
        if ($resource === 'drivers' && $request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(fn ($driverQuery) => $driverQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('employee_number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('license_number', 'like', "%{$search}%"));
        }

        return $this->paginated($query->latest()->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function rootStore(SaveVehicleResourceRequest $request, string $resource)
    {
        $this->authorizeAction($request, $resource, 'create');

        return $this->createResource($request, $resource);
    }

    public function driverFile(Request $request, Driver $driver, string $type)
    {
        $this->authorizeAction($request, 'drivers', 'view');
        abort_unless(in_array($type, ['driver', 'license'], true), 404);
        $path = $type === 'driver' ? $driver->profile_photo : $driver->license_photo;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, basename($path));
    }

    private function createResource(SaveVehicleResourceRequest $request, string $resource, ?Vehicle $vehicle = null)
    {
        $class = $this->class($resource);
        $data = $request->validated();
        if ($vehicle) {
            $data['vehicle_id'] = $vehicle->id;
        }
        if ($resource === 'documents' && $request->hasFile('file')) {
            $data['file_path'] = $request->file('file')->store(
                "businesses/{$request->user()->business_id}/vehicles/{$vehicle->id}/documents"
            );
        }
        if ($resource === 'drivers') {
            $data['employee_number'] = ($data['employee_number'] ?? null) ?: $this->generateEmployeeId($request);
            if ($request->hasFile('driver_photo')) {
                $data['profile_photo'] = $request->file('driver_photo')->store("businesses/{$request->user()->business_id}/drivers/photos");
            }
            if ($request->hasFile('license_photo')) {
                $data['license_photo'] = $request->file('license_photo')->store("businesses/{$request->user()->business_id}/drivers/licenses");
            }
        }
        if ($resource === 'schedules' && $vehicle) {
            $data = $this->calculateScheduleDueValues($data, $vehicle);
        }
        unset($data['file'], $data['driver_photo']);
        $columns = (new $class)->getConnection()->getSchemaBuilder()->getColumnListing((new $class)->getTable());
        foreach (['recorded_by', 'reported_by', 'assigned_by'] as $key) {
            if (in_array($key, $columns)) {
                $data[$key] = $request->user()->id;
            }
        }
        if ($resource === 'issues') {
            $data['reported_at'] ??= now();
        }
        if ($resource === 'assignments') {
            $data['assigned_at'] ??= now();
        }
        if ($resource === 'fuel') {
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

    public function update(SaveVehicleResourceRequest $request, int $id, string $resource)
    {
        $this->authorizeAction($request, $resource, 'update');
        $model = $this->class($resource)::findOrFail($id);
        $oldValues = $model->toArray();
        $data = $request->validated();
        if ($resource === 'drivers') {
            if ($request->hasFile('driver_photo')) {
                $oldPath = $model->profile_photo;
                $data['profile_photo'] = $request->file('driver_photo')->store("businesses/{$request->user()->business_id}/drivers/photos");
                if ($oldPath) {
                    Storage::delete($oldPath);
                }
            }
            if ($request->hasFile('license_photo')) {
                $oldPath = $model->license_photo;
                $data['license_photo'] = $request->file('license_photo')->store("businesses/{$request->user()->business_id}/drivers/licenses");
                if ($oldPath) {
                    Storage::delete($oldPath);
                }
            }
            unset($data['driver_photo'], $data['license_photo']);
        }
        $model->update($data);
        app(AuditService::class)->record("{$resource}.updated", $model, $oldValues);

        return $this->ok($model, ucfirst($resource).' updated.');
    }

    public function updateNested(SaveVehicleResourceRequest $request, Vehicle $vehicle, string $resource, int $id)
    {
        $this->authorizeAction($request, $resource, 'update');
        $model = $this->class($resource)::where('vehicle_id', $vehicle->id)->findOrFail($id);
        $oldValues = $model->toArray();
        $data = $request->validated();

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

        DB::transaction(function () use ($model, $data, $resource, $oldValues) {
            $model->update($data);
            if ($resource === 'fuel') {
                app(ExpenseSyncService::class)->fuel($model);
            }
            app(AuditService::class)->record("{$resource}.updated", $model, $oldValues);
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

    private function class(string $resource): string
    {
        abort_unless(isset(self::MAP[$resource]), 404);

        return self::MAP[$resource];
    }

    private function authorizeAction(Request $request, string $resource, string $action): void
    {
        abort_unless($request->user()->can("{$resource}.{$action}"), 403);
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
