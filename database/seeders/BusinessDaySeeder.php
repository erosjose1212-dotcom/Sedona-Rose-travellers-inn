<?php

namespace Database\Seeders;

use App\Models\BusinessDay;
use Illuminate\Database\Seeder;

class BusinessDaySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Financial sample records may be seeded only in an isolated testing database.');
        }
        BusinessDay::factory()->create();
    }
}
