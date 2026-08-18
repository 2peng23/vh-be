<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMaintenanceRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'record_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'performed_by' => ['nullable', 'string', 'max:150'],
            'service_provider' => ['nullable', 'string', 'max:150'],
            'maintenance_type' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['performed_by_name', 'service_provider', 'service_date', 'mileage', 'maintenance_type'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ];
    }
}
