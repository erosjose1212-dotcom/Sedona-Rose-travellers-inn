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
        // 1. Folio Discount Columns (Authoritative Fixed Discount Engine)
        Schema::table('folios', function (Blueprint $table) {
            if (!Schema::hasColumn('folios', 'discount_type')) {
                $table->string('discount_type', 20)->default('NONE')->after('discount_amount');
            }
            if (!Schema::hasColumn('folios', 'discount_id_ref')) {
                $table->string('discount_id_ref', 100)->nullable()->after('discount_type');
            }
        });

        // 2. Shift Float & Drawer Reconciliation Columns
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'opening_float')) {
                $table->decimal('opening_float', 10, 2)->default(5000.00)->after('opened_at');
            }
            if (!Schema::hasColumn('shifts', 'total_expenses')) {
                $table->decimal('total_expenses', 10, 2)->default(0.00)->after('gross_revenue');
            }
            if (!Schema::hasColumn('shifts', 'expected_cash')) {
                $table->decimal('expected_cash', 10, 2)->default(0.00)->after('cash_total');
            }
            if (!Schema::hasColumn('shifts', 'cash_variance')) {
                $table->decimal('cash_variance', 10, 2)->default(0.00)->after('expected_cash');
            }
        });

        // 3. Link Operational Expenses to Shifts
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('user_id')->constrained('shifts')->nullOnDelete();
            }
        });

        // 4. POS Items Inventory Tracking Columns
        Schema::table('pos_items', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_items', 'stock_quantity')) {
                $table->integer('stock_quantity')->nullable()->default(50)->after('price');
            }
            if (!Schema::hasColumn('pos_items', 'is_tracked')) {
                $table->boolean('is_tracked')->default(true)->after('stock_quantity');
            }
            if (!Schema::hasColumn('pos_items', 'reorder_level')) {
                $table->integer('reorder_level')->default(5)->after('is_tracked');
            }
        });

        // 5. Append-Only Inventory Events Ledger
        if (!Schema::hasTable('inventory_events')) {
            Schema::create('inventory_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pos_item_id')->constrained('pos_items')->cascadeOnDelete();
                $table->string('item_name');
                $table->string('event_type', 30); // stock_set, sold, adjustment, restock
                $table->integer('quantity_change');
                $table->integer('balance_after');
                $table->string('shift_id', 50)->nullable();
                $table->string('reference_id', 50)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('operator_name', 100)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_events');

        Schema::table('pos_items', function (Blueprint $table) {
            $table->dropColumn(['stock_quantity', 'is_tracked', 'reorder_level']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['opening_float', 'total_expenses', 'expected_cash', 'cash_variance']);
        });

        Schema::table('folios', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_id_ref']);
        });
    }
};
