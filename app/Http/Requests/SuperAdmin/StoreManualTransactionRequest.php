<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', Rule::exists('businesses', 'id')],
            'plan' => ['required', Rule::in(['trial', 'starter', 'business', 'enterprise'])],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')],
            'paid_at' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
