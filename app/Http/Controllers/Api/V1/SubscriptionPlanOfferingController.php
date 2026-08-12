<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Business;
use App\Models\SubscriptionPlanOffering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $data = $this->validated($request);
        $offering = DB::transaction(function () use ($data) {
            $offering = SubscriptionPlanOffering::create($data);
            $this->synchronizeVehicleLimit($data['plan'], (int) $data['vehicle_limit']);

            return $offering;
        });

        return $this->ok($offering, 'Plan offering created.', 201);
    }

    /** Update the price, limits, details, or availability of an offering. */
    public function update(Request $request, SubscriptionPlanOffering $plan_offering)
    {
        $data = $this->validated($request, $plan_offering);
        DB::transaction(function () use ($plan_offering, $data) {
            $plan_offering->update($data);
            $this->synchronizeVehicleLimit($data['plan'], (int) $data['vehicle_limit']);
        });

        return $this->ok($plan_offering->fresh(), 'Plan offering updated.');
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

    /**
     * Keep the tier limit consistent while retaining deliberate higher overrides.
     *
     * Businesses without an override inherit the configured plan limit directly.
     * A lower override is cleared so it can inherit the improved plan allowance;
     * a higher override remains untouched because it was assigned manually.
     */
    private function synchronizeVehicleLimit(string $plan, int $vehicleLimit): void
    {
        SubscriptionPlanOffering::query()
            ->where('plan', $plan)
            ->update(['vehicle_limit' => $vehicleLimit]);

        Business::query()
            ->where('subscription_plan', $plan)
            ->whereNotNull('vehicle_limit_override')
            ->where('vehicle_limit_override', '<=', $vehicleLimit)
            ->update(['vehicle_limit_override' => null]);
    }
}
