<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionPlanOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::in(['trial', 'starter', 'business', 'enterprise']), Rule::unique('subscription_plan_offerings')->where(fn ($query) => $query->where('duration_months', $this->integer('duration_months')))],
            'name' => ['required', 'string', 'max:100'],
            'duration_months' => ['required', 'integer', Rule::in($this->input('plan') === 'trial' ? [1] : [1, 6, 12])],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'vehicle_limit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'details' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
