<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListExpenseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'vehicle_id' => ['nullable', 'integer'],
            'vehicle_code' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string'],
            'format' => ['nullable', Rule::in(['json', 'csv', 'xlsx'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ];
    }
}
