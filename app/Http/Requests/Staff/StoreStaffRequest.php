<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->business_id !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['sometimes', Rule::in(['staff'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $existingUser = User::withTrashed()
                    ->where('email', $this->input('email'))
                    ->first();

                if (! $existingUser) {
                    return;
                }

                $canRestoreSameBusinessStaff = $existingUser->trashed()
                    && $existingUser->business_id === $this->user()->business_id
                    && $existingUser->role->value === 'staff';

                if (! $canRestoreSameBusinessStaff) {
                    $validator->errors()->add('email', 'The email has already been taken.');
                }
            },
        ];
    }
}
