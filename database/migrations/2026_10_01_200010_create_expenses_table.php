<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('voucher_number', 30)->unique();
            $table->date('expense_date');
            $table->string('category', 50); // utilities, laundry_linens, maintenance_repairs, kitchen_inventory, staff_allowances, administrative, other
            $table->string('description', 255);
            $table->decimal('amount', 10, 2);
            $table->string('payment_source', 30)->default('cash_drawer'); // cash_drawer, petty_cash, bank_transfer
            $table->string('receipt_reference', 50)->nullable();
            $table->string('status', 20)->default('approved'); // approved, pending, voided
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
