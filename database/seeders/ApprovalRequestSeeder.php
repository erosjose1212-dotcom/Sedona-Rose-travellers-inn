<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use Illuminate\Database\Seeder;

class ApprovalRequestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Financial sample records may be seeded only in an isolated testing database.');
        }
        ApprovalRequest::factory()->create();
    }
}
