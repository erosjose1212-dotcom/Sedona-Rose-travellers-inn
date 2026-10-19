<?php

namespace Database\Seeders;

use App\Models\CashMovement;
use Illuminate\Database\Seeder;

class CashMovementSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Financial sample records may be seeded only in an isolated testing database.');
        }
        CashMovement::factory()->create();
    }
}
