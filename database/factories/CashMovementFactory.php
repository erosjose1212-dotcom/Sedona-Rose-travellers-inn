<?php

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\User;
use App\Services\BusinessDayService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashMovement> */
class CashMovementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'shift_id' => fn (): int => Shift::create(['opened_by' => User::factory()->create()->id, 'opened_at' => now()->subHours(4), 'closed_at' => now(), 'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 500])->id,
            'user_id' => User::factory(), 'approval_request_id' => ApprovalRequest::factory(), 'operation_key' => fake()->uuid(), 'kind' => 'cash_drop', 'destination' => 'safe', 'amount' => -100, 'reference' => fake()->uuid(), 'business_date' => app(BusinessDayService::class)->date(), 'posted_at' => now(),
        ];
    }
}
