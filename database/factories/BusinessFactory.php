<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return ['name' => $name, 'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999), 'email' => fake()->unique()->companyEmail(), 'timezone' => 'Asia/Manila', 'currency' => 'PHP', 'subscription_plan' => 'trial', 'subscription_status' => 'active', 'status' => 'active', 'plan_started_at' => now(), 'plan_ends_at' => now()->addDays(30)];
    }
}
