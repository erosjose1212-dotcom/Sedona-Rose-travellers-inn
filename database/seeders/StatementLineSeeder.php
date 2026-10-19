<?php

namespace Database\Seeders;

use App\Models\StatementLine;
use Illuminate\Database\Seeder;

class StatementLineSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Financial sample records may be seeded only in an isolated testing database.');
        }
        StatementLine::factory()->create();
    }
}
