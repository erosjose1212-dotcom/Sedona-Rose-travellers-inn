<?php

namespace Database\Factories;

use App\Models\DiscountRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountRate>
 */
class DiscountRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discount_type' => 'DC', 'room_tier' => 'CLASSIC', 'duration' => '3h', 'amount' => 40,
        ];
    }
}
