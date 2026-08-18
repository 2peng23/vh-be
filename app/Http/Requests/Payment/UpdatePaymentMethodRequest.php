<?php

namespace App\Http\Requests\Payment;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        $paymentMethod = $this->route('payment_method') ?? $this->route('paymentMethod');

        return [
            'name' => ['required', Rule::in(['Maya', 'GCash', 'BDO', 'Chinabank', 'UnionBank']), Rule::unique('payment_methods', 'name')->ignore($paymentMethod instanceof PaymentMethod ? $paymentMethod->id : null)],
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:150'],
            'qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_qr' => ['nullable', 'boolean'],
        ];
    }
}
