<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $vehicle = $this->route('vehicle');

        return [
            'plate_number' => ['required', 'string', 'max:30', Rule::unique('vehicles')->where('business_id', $this->user()->business_id)->ignore($vehicle instanceof Vehicle ? $vehicle->id : null)],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'variant' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'vin' => ['nullable', 'string', 'max:100'],
            'engine_number' => ['nullable', 'string', 'max:100'],
            'chassis_number' => ['nullable', 'string', 'max:100'],
            'current_mileage' => ['sometimes', 'integer', 'min:0'],
            'acquisition_date' => ['nullable', 'date'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
