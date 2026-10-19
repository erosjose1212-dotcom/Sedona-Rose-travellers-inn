<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // cashier
            $table->unsignedBigInteger('booking_id')->nullable(); // soft ref, no FK constraint
            $table->string('transaction_id', 20)->unique()->nullable(); // SCTI-######
            $table->enum('rate_tier', ['3h', '6h', '12h', '24h', 'promo']);
            $table->dateTime('checked_in_at');
            $table->dateTime('expected_checkout_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->enum('status', ['active', 'checked_out', 'voided'])->default('active');

            // Surcharges
            $table->unsignedTinyInteger('extra_persons')->default(0);
            $table->unsignedTinyInteger('extra_bedding')->default(0);
            $table->unsignedTinyInteger('extra_towels')->default(0);
            $table->unsignedSmallInteger('extra_hours')->default(0);

            // Folio line items stored as JSON array
            $table->json('pos_items')->nullable();          // [{item, qty, price}]

            // Billing
            $table->decimal('room_charge',      10, 2)->default(0);
            $table->decimal('surcharge_total',  10, 2)->default(0);
            $table->decimal('pos_total',        10, 2)->default(0);
            $table->decimal('gross_total',      10, 2)->default(0);
            $table->decimal('discount_amount',  10, 2)->default(0);
            $table->boolean('senior_pwd_discount')->default(false);
            $table->decimal('net_total',        10, 2)->default(0);

            // Settlement
            $table->enum('payment_method', ['cash', 'gcash', 'split'])->nullable();
            $table->decimal('cash_tendered',    10, 2)->default(0);
            $table->decimal('gcash_amount',     10, 2)->default(0);
            $table->string('gcash_reference', 50)->nullable();
            $table->decimal('change_due',       10, 2)->default(0);

            // Force checkout
            $table->boolean('force_checkout')->default(false);
            $table->string('force_reason')->nullable();
            $table->foreignId('force_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
