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
        $query = SubscriptionPlanOffering::query()
            ->orderBy('plan')
            ->orderBy('duration_months');

        if ($user->role->value !== 'super_admin') {
            $query->where('is_active', true);
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

        if (! $offerings->contains('plan', 'trial')) {
            $offerings->prepend((object) [
                'id' => 0,
                'plan' => 'trial',
                'name' => 'Free Trial',
                'duration_months' => 1,
                'duration_days' => SubscriptionPlans::TRIAL_DAYS,
                'price' => '0.00',
                'vehicle_limit' => SubscriptionPlans::defaultVehicleLimit('trial'),
                'details' => 'Explore Vehicle Hub before choosing a paid subscription.',
            ]);
        }

        return $offerings->values()->map(fn ($offering) => [
            'id' => (int) $offering->id,
            'plan' => (string) $offering->plan,
            'name' => (string) $offering->name,
            'duration_months' => (int) $offering->duration_months,
            'duration_days' => property_exists($offering, 'duration_days') ? (int) $offering->duration_days : null,
            'price' => (string) $offering->price,
            'vehicle_limit' => (int) $offering->vehicle_limit,
            'details' => $offering->details,
        ]);
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
