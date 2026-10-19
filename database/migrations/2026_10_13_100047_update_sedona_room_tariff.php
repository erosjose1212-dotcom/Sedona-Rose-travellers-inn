<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ratesByType = [
            'Classic Room' => [395, 525, 785, 1195, 2100, 995],
            'Premium Room' => [495, 625, 885, 1295, 2300, 1055],
            'VIP Suite Room' => [695, 825, 1085, 1395, 2500, 1155],
        ];

        foreach ($ratesByType as $type => $rates) {
            DB::table('rooms')->where('type', $type)->where('is_staff_quarters', false)->update([
                'base_rate_3h' => $rates[0],
                'base_rate_4h' => $rates[1],
                'base_rate_6h' => $rates[2],
                'base_rate_12h' => $rates[3],
                'base_rate_24h' => $rates[4],
                'base_rate_promo' => $rates[5],
            ]);
        }

        DB::table('pos_items')->where('name', 'Clean Bed Sheet (Single/Double)')->update(['price' => 100]);
    }

    /**
     * Historical custom prices cannot be reconstructed; use a forward tariff update.
     */
    public function down(): void {}
};
