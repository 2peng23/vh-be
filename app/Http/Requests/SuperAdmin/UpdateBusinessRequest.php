<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'subscription_plan' => ['sometimes', 'required', Rule::in(['trial', 'starter', 'business', 'enterprise'])],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
            'plan_ends_at' => ['sometimes', 'nullable', 'date'],
            'vehicle_limit_override' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
