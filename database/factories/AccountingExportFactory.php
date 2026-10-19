<?php

namespace Database\Factories;

use App\Models\AccountingExport;
use App\Models\BusinessDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountingExport> */
class AccountingExportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_day_id' => BusinessDay::factory(), 'version' => 1, 'destination' => 'Accountant CSV', 'status' => 'draft', 'lines' => [], 'configuration' => [], 'sha256' => hash('sha256', '[]'), 'created_by' => User::factory(),
        ];
    }
}
