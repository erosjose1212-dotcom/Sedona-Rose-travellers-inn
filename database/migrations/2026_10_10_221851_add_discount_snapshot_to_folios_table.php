<?php

use App\Services\DiscountService;
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
        Schema::table('folios', function (Blueprint $table) {
            $table->json('discount_schedule')->nullable();
            $table->decimal('discount_override_amount', 10, 2)->nullable();
        });
        DB::table('folios')->where('status', 'active')->update([
            'discount_schedule' => json_encode(DiscountService::RATES, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn(['discount_schedule', 'discount_override_amount']);
        });
    }
};
