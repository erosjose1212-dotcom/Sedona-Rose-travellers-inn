<?php

namespace Database\Seeders;

use App\Models\DiscountRate;
use App\Services\DiscountService;
use Illuminate\Database\Seeder;

class DiscountRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (DiscountService::RATES as $type => $rooms) {
            foreach ($rooms as $tier => $durations) {
                foreach ($durations as $duration => $amount) {
                    DiscountRate::firstOrCreate(['discount_type' => $type, 'room_tier' => $tier, 'duration' => $duration], ['amount' => $amount]);
                }
            }
        }
    }
}
