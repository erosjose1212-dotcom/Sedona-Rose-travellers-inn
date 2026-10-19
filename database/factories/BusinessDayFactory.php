<?php

namespace Database\Factories;

use App\Models\BusinessDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessDay> */
class BusinessDayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_date' => fake()->unique()->date(), 'status' => 'open', 'version' => 0,
        ];
    }
}
