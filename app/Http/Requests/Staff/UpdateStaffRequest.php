<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->business_id !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules(): array
    {
        $staff = $this->route('staff');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($staff instanceof User ? $staff->id : null)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['sometimes', Rule::in(['staff'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'password' => ['sometimes', 'confirmed', Password::defaults()],
        ];
    }
}
