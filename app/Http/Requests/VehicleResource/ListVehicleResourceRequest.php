<?php

namespace App\Http\Requests\VehicleResource;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVehicleResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:100'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'string', 'max:50'],
            'record_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'recorded_by' => ['nullable', 'string', 'max:150'],
            'sort_by' => ['nullable', 'string', 'max:100'],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
