<?php

namespace Database\Seeders;

use App\Models\AccountingExport;
use Illuminate\Database\Seeder;

class AccountingExportSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Financial sample records may be seeded only in an isolated testing database.');
        }
        AccountingExport::factory()->create();
    }
}
