<?php

namespace App\Http\Requests\Maintenance;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $vehicle = $this->route('vehicle');

        return [
            'maintenance_schedule_id' => ['nullable', Rule::exists('maintenance_schedules', 'id')->where('vehicle_id', $vehicle instanceof Vehicle ? $vehicle->id : null)->where('business_id', $this->user()->business_id)],
            'service_provider' => ['nullable', 'string', 'max:150'],
            'service_date' => ['required', 'date'],
            'mileage' => ['required', 'integer', 'min:0'],
            'maintenance_type' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'parts_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'parts' => ['nullable', 'array'],
            'parts.*.part_name' => ['required', 'string'],
            'parts.*.part_number' => ['nullable', 'string'],
            'parts.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'parts.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'parts.*.supplier' => ['nullable', 'string'],
        ];
    }
}
