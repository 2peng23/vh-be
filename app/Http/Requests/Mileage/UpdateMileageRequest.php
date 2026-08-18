<?php

namespace App\Http\Requests\Mileage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMileageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mileage' => ['sometimes', 'required', 'integer', 'min:0'],
            'recorded_at' => ['sometimes', 'required', 'date', 'before_or_equal:now'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
