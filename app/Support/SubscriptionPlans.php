<?php

namespace App\Support;

use App\Models\Business;

final class SubscriptionPlans
{
    public const VEHICLE_LIMITS = [
        'trial' => 3,
        'starter' => 5,
        'business' => 25,
        'enterprise' => 100,
    ];

    public static function tier(Business $business): string
    {
        return array_key_exists($business->subscription_plan, self::VEHICLE_LIMITS)
            ? $business->subscription_plan
            : 'trial';
    }

    public static function vehicleLimit(Business $business): int
    {
        if ($business->vehicle_limit_override !== null) {
            return (int) $business->vehicle_limit_override;
        }

        return self::VEHICLE_LIMITS[self::tier($business)];
    }

    public static function summary(Business $business): array
    {
        $tier = self::tier($business);
        $limit = self::vehicleLimit($business);
        $count = isset($business->vehicles_count)
            ? (int) $business->vehicles_count
            : $business->vehicles()->count();

        return [
            'tier' => $tier,
            'label' => ucfirst($tier),
            'status' => $business->subscription_status,
            'vehicle_limit' => $limit,
            'default_vehicle_limit' => self::VEHICLE_LIMITS[$tier],
            'has_custom_vehicle_limit' => $business->vehicle_limit_override !== null,
            'vehicle_count' => $count,
            'vehicles_remaining' => max(0, $limit - $count),
            'vehicle_limit_reached' => $count >= $limit,
            'usage_percent' => min(100, (int) round(($count / $limit) * 100)),
            'plan_ends_at' => $business->plan_ends_at?->toISOString(),
            'plan_ended' => $business->planHasEnded(),
        ];
    }
}
