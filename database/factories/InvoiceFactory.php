<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\User;
use App\Services\BusinessDayService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'number' => 'TEST-'.fake()->unique()->numerify('########'), 'business_date' => app(BusinessDayService::class)->date(), 'document' => ['gross_cents' => 10000, 'discount_cents' => 0, 'net_cents' => 10000, 'tax_cents' => 0, 'tax_base_cents' => 10000, 'deposit_applied_cents' => 0, 'issuer' => ['legal_name' => 'Factory test issuer']], 'user_id' => User::factory(),
        ];
    }
}
