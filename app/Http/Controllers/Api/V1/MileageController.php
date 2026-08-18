<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Mileage\ListMileageLogsRequest;
use App\Http\Requests\Mileage\StoreMileageRequest;
use App\Http\Requests\Mileage\UpdateMileageRequest;
use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Services\MileageService;
use Illuminate\Support\Facades\Storage;

class MileageController extends ApiController
{
    public function index(ListMileageLogsRequest $request, Vehicle $vehicle)
    {
        return $this->paginated($vehicle->mileageLogs()
            ->with('recorder:id,name')
            ->latest('recorded_at')
            ->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    public function store(StoreMileageRequest $request, Vehicle $vehicle, MileageService $mileageService)
    {
        $mileageLog = $mileageService->record($vehicle, $request->user(), $request->validated(), $request->file('photo'));

        return $this->ok($mileageLog, 'Mileage recorded.', 201);
    }

    public function photo(MileageLog $mileageLog)
    {
        abort_unless($mileageLog->photo && Storage::exists($mileageLog->photo), 404);

        return Storage::download($mileageLog->photo, basename($mileageLog->photo));
    }

    public function update(UpdateMileageRequest $request, Vehicle $vehicle, MileageLog $mileageLog, MileageService $mileageService)
    {
        abort_unless($mileageLog->vehicle_id === $vehicle->id, 404);
        $mileageLog = $mileageService->update($vehicle, $mileageLog, $request->user(), $request->validated(), $request->file('photo'));

        return $this->ok($mileageLog, 'Mileage updated.');
    }

    public function destroy(Vehicle $vehicle, MileageLog $mileageLog, MileageService $mileageService)
    {
        abort_unless($mileageLog->vehicle_id === $vehicle->id, 404);
        $mileageService->delete($vehicle, $mileageLog);

        return $this->ok(null, 'Mileage deleted.');
    }
}
