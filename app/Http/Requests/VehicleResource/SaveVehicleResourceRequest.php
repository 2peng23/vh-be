<?php

namespace App\Http\Requests\VehicleResource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVehicleResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $required = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';
        $rules = match ($resource) {
            'documents' => ['document_type' => [$required, 'string'], 'document_number' => ['nullable', 'string'], 'issue_date' => ['nullable', 'date'], 'expiration_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string'], 'status' => ['nullable', 'string']],
            'expenses' => ['category' => [$required, 'string'], 'amount' => [$required, 'numeric', 'min:0'], 'expense_date' => [$required, 'date'], 'vendor' => ['nullable', 'string'], 'description' => ['nullable', 'string'], 'receipt_path' => ['nullable', 'string']],
            'issues' => ['title' => [$required, 'string'], 'description' => [$required, 'string'], 'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])], 'category' => [$required, 'string'], 'status' => ['nullable', Rule::in(['reported', 'for_inspection', 'approved', 'in_repair', 'completed', 'cancelled'])], 'mileage' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('business_id', $this->user()->business_id)], 'assigned_to_name' => ['nullable', 'string', 'max:150'], 'resolution_notes' => ['nullable', 'string'], 'estimated_cost' => ['nullable', 'numeric'], 'actual_cost' => ['nullable', 'numeric']],
            'fuel' => ['fuel_date' => [$required, 'date'], 'mileage' => [$required, 'integer'], 'liters' => [$required, 'numeric', 'min:0.001'], 'price_per_liter' => [$required, 'numeric', 'min:0'], 'fuel_type' => ['nullable', 'string'], 'station' => ['nullable', 'string'], 'receipt_path' => ['nullable', 'string'], 'notes' => ['nullable', 'string']],
            'schedules' => ['maintenance_type' => [$required, 'string'], 'interval_type' => [$required, Rule::in(['mileage', 'date', 'both'])], 'interval_km' => ['nullable', 'integer'], 'interval_months' => ['nullable', 'integer'], 'last_service_mileage' => ['nullable', 'integer'], 'last_service_date' => ['nullable', 'date'], 'next_service_mileage' => ['nullable', 'integer'], 'next_service_date' => ['nullable', 'date'], 'reminder_km' => ['nullable', 'integer'], 'reminder_days' => ['nullable', 'integer'], 'status' => ['nullable', 'string']],
            'drivers' => ['user_id' => ['nullable', 'integer'], 'employee_number' => ['nullable', 'string', 'max:100', Rule::unique('drivers')->where('business_id', $this->user()->business_id)->ignore($this->route('id'))], 'name' => [$required, 'string'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string'], 'license_number' => ['nullable', 'string'], 'license_type' => ['nullable', 'string'], 'license_expiration' => ['nullable', 'date'], 'date_hired' => ['nullable', 'date'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'driver_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240'], 'license_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240']],
            'assignments' => ['vehicle_id' => [$required, 'integer'], 'driver_id' => [$required, 'integer'], 'assigned_at' => ['nullable', 'date'], 'returned_at' => ['nullable', 'date'], 'status' => ['nullable', Rule::in(['active', 'completed', 'cancelled'])], 'notes' => ['nullable', 'string']],
            default => [],
        };

        if ($resource === 'documents') {
            $rules['file'] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
        }

        return $rules;
    }
}
