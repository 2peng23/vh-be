<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Vehicle\ListVehiclesRequest;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Models\Vehicle;
use App\Services\Vehicle\VehicleService;

class VehicleController extends ApiController
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function index(ListVehiclesRequest $request)
    {
        return $this->paginated($this->vehicleService->paginate($request->validated()));
    }

    public function store(StoreVehicleRequest $request)
    {
        $vehicle = $this->vehicleService->create($request->user(), $request->validated());

        return $this->ok($vehicle, 'Vehicle created.', 201);
    }

    public function show(Vehicle $vehicle)
    {
        $this->access($vehicle);

        return $this->ok(
            $vehicle
                ->load(['schedules', 'documents', 'assignments'])
                ->loadSum('expenses', 'amount')
        );
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        $this->access($vehicle);
        $vehicle = $this->vehicleService->update($vehicle, $request->validated());

        return $this->ok($vehicle, 'Vehicle updated.');
    }

    public function destroy(Vehicle $vehicle)
    {
        $this->access($vehicle);
        $this->vehicleService->delete($vehicle);

        return $this->ok(null, 'Vehicle archived.');
    }

    private function access(Vehicle $vehicle): void
    {
        // Tenant scoping and the route permission middleware authorize access.
    }
}
