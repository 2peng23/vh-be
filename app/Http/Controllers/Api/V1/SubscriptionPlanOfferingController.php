<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SubscriptionPlanOffering;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPlanOfferingController extends ApiController
{
    /** Return every offering to administrators and active offerings to owners. */
    public function index(Request $request)
    {
        $query = SubscriptionPlanOffering::query()->orderBy('plan')->orderBy('duration_months');
        if ($request->user()->role->value !== 'super_admin') {
            $query->where('is_active', true);
        }

        return $this->ok($query->get());
    }

    /** Create a configurable plan-duration offering. */
    public function store(Request $request)
    {
        $offering = SubscriptionPlanOffering::create($this->validated($request));

        return $this->ok($offering, 'Plan offering created.', 201);
    }

    /** Update the price, limits, details, or availability of an offering. */
    public function update(Request $request, SubscriptionPlanOffering $subscriptionPlanOffering)
    {
        $subscriptionPlanOffering->update($this->validated($request, $subscriptionPlanOffering));

        return $this->ok($subscriptionPlanOffering->fresh(), 'Plan offering updated.');
    }

    /** Validate a plan offering while restricting durations to supported billing periods. */
    private function validated(Request $request, ?SubscriptionPlanOffering $offering = null): array
    {
        return $request->validate([
            'plan' => ['required', Rule::in(['starter', 'business', 'enterprise']), Rule::unique('subscription_plan_offerings')->where(fn ($query) => $query->where('duration_months', $request->integer('duration_months')))->ignore($offering?->id)],
            'name' => 'required|string|max:100',
            'duration_months' => ['required', 'integer', Rule::in([1, 6, 12])],
            'price' => 'required|numeric|min:0|max:9999999999.99',
            'vehicle_limit' => 'required|integer|min:1|max:1000000',
            'details' => 'nullable|string|max:2000',
            'is_active' => 'required|boolean',
        ]);
    }
}
