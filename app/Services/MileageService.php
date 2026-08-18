<?php

namespace App\Services;

use App\Models\MileageLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MileageService
{
    public function record(Vehicle $vehicle, User $user, array $data, ?UploadedFile $photo = null): MileageLog
    {
        $photoPath = null;

        try {
            if ($photo !== null) {
                $photoPath = $photo->store("businesses/{$user->business_id}/vehicles/{$vehicle->id}/mileage");
                $data['photo'] = $photoPath;
            }

            return DB::transaction(function () use ($vehicle, $user, $data) {
                $override = (bool) ($data['override'] ?? false);
                if ($data['mileage'] < $vehicle->current_mileage && ! ($override && $user->can('mileage.override'))) {
                    throw ValidationException::withMessages(['mileage' => 'Mileage cannot be lower than the current reading.']);
                }

                $mileageLog = MileageLog::create([
                    'vehicle_id' => $vehicle->id,
                    'recorded_by' => $user->id,
                    'mileage' => $data['mileage'],
                    'recorded_at' => $data['recorded_at'] ?? now(),
                    'notes' => $data['notes'] ?? null,
                    'photo' => $data['photo'] ?? null,
                    'is_override' => $override,
                ]);
                $previousMileage = $vehicle->current_mileage;
                $vehicle->update(['current_mileage' => $data['mileage']]);
                if ($override) {
                    app(AuditService::class)->record('mileage.override', $mileageLog, ['mileage' => $previousMileage]);
                } else {
                    app(AuditService::class)->record('mileage.recorded', $mileageLog);
                }

                return $mileageLog;
            });
        } catch (\Throwable $exception) {
            if ($photoPath) {
                Storage::delete($photoPath);
            }

            throw $exception;
        }
    }

    public function update(Vehicle $vehicle, MileageLog $mileageLog, User $user, array $data, ?UploadedFile $photo = null): MileageLog
    {
        $override = (bool) ($data['override'] ?? false);
        unset($data['override']);

        abort_if($override && ! $user->can('mileage.override'), 403);

        if (array_key_exists('mileage', $data)
            && (int) $data['mileage'] !== (int) $mileageLog->mileage
            && (int) $data['mileage'] < (int) $vehicle->current_mileage
            && ! $override) {
            throw ValidationException::withMessages([
                'mileage' => 'Enable mileage override to save a lower reading.',
            ]);
        }

        $oldPhoto = $mileageLog->photo;
        if ($photo !== null) {
            $data['photo'] = $photo->store("businesses/{$user->business_id}/vehicles/{$vehicle->id}/mileage");
        }

        $wasOverride = $mileageLog->is_override;
        $oldValues = $mileageLog->toArray();
        if ($override) {
            $data['is_override'] = true;
        }
        $mileageLog->update($data);
        $this->syncCurrentMileage($vehicle);
        app(AuditService::class)->record('mileage.updated', $mileageLog, $oldValues);

        if ($override && ! $wasOverride) {
            app(AuditService::class)->record('mileage.override', $mileageLog, [
                'mileage' => $mileageLog->getOriginal('mileage'),
            ]);
        }

        if ($photo !== null && $oldPhoto) {
            Storage::delete($oldPhoto);
        }

        return $mileageLog->fresh();
    }

    public function delete(Vehicle $vehicle, MileageLog $mileageLog): void
    {
        $photo = $mileageLog->photo;
        $mileageLog->delete();
        app(AuditService::class)->record('mileage.deleted', $mileageLog);
        $this->syncCurrentMileage($vehicle);

        if ($photo) {
            Storage::delete($photo);
        }
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
