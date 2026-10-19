<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parent order (one per transaction)
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // cashier
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete(); // null = walk-in POS
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id', 20)->nullable();
            $table->enum('type', ['room_charge', 'walkin_pos'])->default('room_charge');
            $table->enum('status', ['new', 'preparing', 'ready', 'delivered'])->default('new');
            $table->text('special_instructions')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            // Walk-in POS settlement
            $table->enum('payment_method', ['cash', 'gcash', 'split', 'charge_to_room'])->nullable();
            $table->decimal('cash_tendered', 10, 2)->default(0);
            $table->decimal('gcash_amount',  10, 2)->default(0);
            $table->string('gcash_reference', 50)->nullable();
            $table->decimal('change_due',    10, 2)->default(0);
            $table->dateTime('dispatched_at')->nullable();
            $table->timestamps();
        });

        // Order line items
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_item_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');            // snapshot at time of order
            $table->decimal('unit_price', 8, 2);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
