<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->value === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'action' => 'required|string|in:approve,reject',
            'rejection_reason' => 'nullable|required_if:action,reject|string|min:3|max:1000',
        ];
    }
}
