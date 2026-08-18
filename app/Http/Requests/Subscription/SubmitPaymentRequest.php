<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->business_id !== null
            && $this->user()?->role->value === 'owner';
    }

    public function rules(): array
    {
        return [
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->filled('payment_reference') && ! $this->hasFile('proof')) {
                    $validator->errors()->add(
                        'payment_reference',
                        'Please provide a payment reference or upload payment proof.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'proof.mimes' => 'Payment proof must be an image or PDF.',
        ];
    }
}
