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
        DB::table('pos_items')->where('name', 'Guest Amenity Hygiene Kit')->update([
            'price' => 0,
            'is_tracked' => true,
        ]);
    }

    /**
     * Previous custom prices and tracking settings cannot be reconstructed; use a forward update.
     */
    public function down(): void {}
};
