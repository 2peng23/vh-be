<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->business_id !== null
            && $this->user()?->role->value === 'owner';
    }

    public function rules(): array
    {
        return [
            'subscription_plan_offering_id' => 'required|integer|exists:subscription_plan_offerings,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
        ];
    }
}
