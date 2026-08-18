<?php

namespace App\Http\Requests\Maintenance;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $vehicle = $this->route('vehicle');

        return [
            'maintenance_schedule_id' => ['sometimes', 'nullable', Rule::exists('maintenance_schedules', 'id')->where('vehicle_id', $vehicle instanceof Vehicle ? $vehicle->id : null)->where('business_id', $this->user()->business_id)],
            'service_provider' => ['sometimes', 'nullable', 'string', 'max:150'],
            'service_date' => ['sometimes', 'required', 'date'],
            'mileage' => ['sometimes', 'required', 'integer', 'min:0'],
            'maintenance_type' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'labor_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'parts_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'other_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
