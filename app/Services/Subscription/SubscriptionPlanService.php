<?php

namespace App\Services\Subscription;

use App\Models\Business;
use App\Models\SubscriptionPlanOffering;
use App\Support\SubscriptionPlans;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanService
{
    public function listForUser($user): Collection
    {
        $this->ensureTrialOffering();

        $query = SubscriptionPlanOffering::query()
            ->orderByRaw("CASE plan WHEN 'trial' THEN 0 WHEN 'starter' THEN 1 WHEN 'business' THEN 2 WHEN 'enterprise' THEN 3 ELSE 99 END")
            ->orderBy('duration_months');

        if ($user->role->value !== 'super_admin') {
            $query->where('is_active', true)
                ->where('plan', '!=', 'trial');
        }

        return $query->get();
    }

    public function create(array $validated): SubscriptionPlanOffering
    {
        return DB::transaction(function () use ($validated) {
            $offering = SubscriptionPlanOffering::create($validated);
            $this->synchronizeVehicleLimit($validated['plan'], (int) $validated['vehicle_limit']);

            return $offering;
        });
    }

    public function update(SubscriptionPlanOffering $offering, array $validated): SubscriptionPlanOffering
    {
        DB::transaction(function () use ($offering, $validated) {
            $offering->update($validated);
            $this->synchronizeVehicleLimit($validated['plan'], (int) $validated['vehicle_limit']);
        });

        return $offering->fresh();
    }

    /** Return active plan offerings that are safe for unauthenticated visitors. */
    public function getPublicActiveOfferings(): Collection
    {
        $this->ensureTrialOffering();

        $offerings = SubscriptionPlanOffering::query()
            ->where('is_active', true)
            ->get([
                'id',
                'plan',
                'name',
                'duration_months',
                'price',
                'vehicle_limit',
                'details',
            ])
            ->sortBy(fn ($offering) => sprintf(
                '%02d-%03d',
                ['trial' => 0, 'starter' => 1, 'business' => 2, 'enterprise' => 3][$offering->plan] ?? 99,
                $offering->duration_months,
            ))
            ->values();

        return $offerings->values()->map(fn ($offering) => [
            'id' => (int) $offering->id,
            'plan' => (string) $offering->plan,
            'name' => (string) $offering->name,
            'duration_months' => (int) $offering->duration_months,
            'duration_days' => $offering->plan === 'trial' ? SubscriptionPlans::TRIAL_DAYS : null,
            'price' => (string) $offering->price,
            'vehicle_limit' => (int) $offering->vehicle_limit,
            'details' => $offering->details,
        ]);
    }

    private function ensureTrialOffering(): void
    {
        SubscriptionPlanOffering::query()->firstOrCreate(
            [
                'plan' => 'trial',
                'duration_months' => 1,
            ],
            [
                'name' => 'Free Trial',
                'price' => 0,
                'vehicle_limit' => SubscriptionPlans::VEHICLE_LIMITS['trial'],
                'details' => 'Explore Vehicle Hub before choosing a paid subscription.',
                'is_active' => true,
            ],
        );
    }

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
