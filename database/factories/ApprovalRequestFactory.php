<?php

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\PosItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApprovalRequest> */
class ApprovalRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => 'stock_adjustment', 'source_type' => PosItem::class,
            'source_id' => fn (): int => PosItem::create(['name' => fake()->unique()->word(), 'price' => 50, 'category' => 'beverages', 'stock_quantity' => 10, 'is_tracked' => true])->id,
            'requested_by' => User::factory(), 'payload' => ['stock_quantity' => 20, 'reason' => 'restock'], 'source_hash' => hash('sha256', json_encode(['stock_quantity' => 10, 'retired_at' => null])), 'status' => 'pending', 'reason' => 'Verified delivery',
        ];
    }
}
