<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('base_rate_4h', 8, 2)->default(0);
        });

        foreach (['folios', 'bookings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->enum('rate_tier', ['3h', '4h', '6h', '12h', '24h', 'promo'])->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['folios', 'bookings'] as $tableName) {
            if (DB::table($tableName)->where('rate_tier', '4h')->exists()) {
                throw new RuntimeException('Cannot remove the four-hour tier while stays or reservations use it.');
            }
        }

        foreach (['folios', 'bookings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->enum('rate_tier', ['3h', '6h', '12h', '24h', 'promo'])->change();
            });
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('base_rate_4h');
        });
    }
};
