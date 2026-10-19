<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            if (!Schema::hasColumn('folios', 'security_deposit')) {
                $table->decimal('security_deposit', 10, 2)->default(0.00)->after('surcharge_total');
            }
            if (!Schema::hasColumn('folios', 'deposit_payment_method')) {
                $table->string('deposit_payment_method', 20)->default('cash')->after('security_deposit');
            }
            if (!Schema::hasColumn('folios', 'deposit_status')) {
                $table->string('deposit_status', 20)->default('none')->after('deposit_payment_method');
            }
            if (!Schema::hasColumn('folios', 'deposit_collected_at')) {
                $table->dateTime('deposit_collected_at')->nullable()->after('deposit_status');
            }
            if (!Schema::hasColumn('folios', 'deposit_refunded_at')) {
                $table->dateTime('deposit_refunded_at')->nullable()->after('deposit_collected_at');
            }
            if (!Schema::hasColumn('folios', 'deposit_refunded_amount')) {
                $table->decimal('deposit_refunded_amount', 10, 2)->default(0.00)->after('deposit_refunded_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn([
                'security_deposit',
                'deposit_payment_method',
                'deposit_status',
                'deposit_collected_at',
                'deposit_refunded_at',
                'deposit_refunded_amount',
            ]);
        });
    }
};
