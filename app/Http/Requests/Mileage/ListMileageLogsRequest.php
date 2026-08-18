<?php

namespace App\Http\Requests\Mileage;

use Illuminate\Foundation\Http\FormRequest;

class ListMileageLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
