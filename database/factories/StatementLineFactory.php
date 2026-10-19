<?php

namespace Database\Factories;

use App\Models\StatementLine;
use App\Models\User;
use App\Services\BusinessDayService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatementLine> */
class StatementLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'gcash', 'import_hash' => hash('sha256', fake()->uuid()), 'fingerprint' => hash('sha256', fake()->uuid()), 'reference' => fake()->unique()->uuid(), 'posted_at' => now(), 'business_date' => app(BusinessDayService::class)->date(), 'gross_amount' => 100, 'fee_amount' => 2, 'net_amount' => 98, 'user_id' => User::factory(),
        ];
    }
}
