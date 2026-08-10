<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Services\AuditService;
use App\Services\MileageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MileageController extends ApiController
{
    public function index(Request $r, Vehicle $vehicle)
    {
        return $this->paginated($vehicle->mileageLogs()
            ->with('recorder:id,name')
            ->latest('recorded_at')
            ->paginate(min((int) $r->input('per_page', 20), 100)));
    }

    public function store(Request $r, Vehicle $vehicle, MileageService $s)
    {
        $data = $r->validate([
            'mileage' => 'required|integer|min:0',
            'recorded_at' => 'nullable|date|before_or_equal:now',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:10240',
            'override' => 'sometimes|boolean',
        ]);

        $path = null;
        try {
            if ($r->hasFile('photo')) {
                $path = $r->file('photo')->store(
                    "businesses/{$r->user()->business_id}/vehicles/{$vehicle->id}/mileage"
                );
                $data['photo'] = $path;
            }

            return $this->ok($s->record($vehicle, $data), 'Mileage recorded.', 201);
        } catch (Throwable $exception) {
            if ($path) {
                Storage::delete($path);
            }

            throw $exception;
        }
    }

    public function photo(MileageLog $mileageLog)
    {
        abort_unless($mileageLog->photo && Storage::exists($mileageLog->photo), 404);

        return Storage::download($mileageLog->photo, basename($mileageLog->photo));
    }

    public function update(Request $r, Vehicle $vehicle, MileageLog $mileageLog)
    {
        abort_unless($mileageLog->vehicle_id === $vehicle->id, 404);

        $data = $r->validate([
            'mileage' => 'sometimes|required|integer|min:0',
            'recorded_at' => 'sometimes|required|date|before_or_equal:now',
            'notes' => 'sometimes|nullable|string',
            'photo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png|max:10240',
            'override' => 'sometimes|boolean',
        ]);

        $override = (bool) ($data['override'] ?? false);
        unset($data['override']);
        abort_if($override && ! $r->user()->can('mileage.override'), 403);
        if (array_key_exists('mileage', $data)
            && (int) $data['mileage'] !== (int) $mileageLog->mileage
            && (int) $data['mileage'] < (int) $vehicle->current_mileage
            && ! $override) {
            throw ValidationException::withMessages([
                'mileage' => 'Enable mileage override to save a lower reading.',
            ]);
        }

        $oldPhoto = $mileageLog->photo;
        if ($r->hasFile('photo')) {
            $data['photo'] = $r->file('photo')->store(
                "businesses/{$r->user()->business_id}/vehicles/{$vehicle->id}/mileage"
            );
        }

        $wasOverride = $mileageLog->is_override;
        $old = $mileageLog->toArray();
        if ($override) {
            $data['is_override'] = true;
        }
        $mileageLog->update($data);
        $this->syncCurrentMileage($vehicle);
        app(AuditService::class)->record('mileage.updated', $mileageLog, $old);

        if ($override && ! $wasOverride) {
            app(AuditService::class)->record('mileage.override', $mileageLog, [
                'mileage' => $mileageLog->getOriginal('mileage'),
            ]);
        }

        if ($r->hasFile('photo') && $oldPhoto) {
            Storage::delete($oldPhoto);
        }

        return $this->ok($mileageLog->fresh(), 'Mileage updated.');
    }

    public function destroy(Request $r, Vehicle $vehicle, MileageLog $mileageLog)
    {
        abort_unless($mileageLog->vehicle_id === $vehicle->id, 404);
        $photo = $mileageLog->photo;
        $mileageLog->delete();
        app(AuditService::class)->record('mileage.deleted', $mileageLog);
        $this->syncCurrentMileage($vehicle);

        if ($photo) {
            Storage::delete($photo);
        }

        return $this->ok(null, 'Mileage deleted.');
    }

    private function syncCurrentMileage(Vehicle $vehicle): void
    {
        $latestMileage = $vehicle->mileageLogs()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->value('mileage');

        $vehicle->update(['current_mileage' => $latestMileage ?? 0]);
    }
}
