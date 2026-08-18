<?php

namespace Database\Factories;

use App\Support\SubscriptionPlans;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        $startsAt = now();

        return ['name' => $name, 'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999), 'email' => fake()->unique()->companyEmail(), 'timezone' => 'Asia/Manila', 'currency' => 'PHP', 'subscription_plan' => 'trial', 'subscription_status' => 'active', 'status' => 'active', 'plan_started_at' => $startsAt, 'plan_ends_at' => SubscriptionPlans::trialEndsAt($startsAt)];
    }
}
