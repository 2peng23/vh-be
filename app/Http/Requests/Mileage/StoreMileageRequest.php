<?php

namespace App\Http\Requests\Mileage;

use Illuminate\Foundation\Http\FormRequest;

class StoreMileageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mileage' => ['required', 'integer', 'min:0'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
