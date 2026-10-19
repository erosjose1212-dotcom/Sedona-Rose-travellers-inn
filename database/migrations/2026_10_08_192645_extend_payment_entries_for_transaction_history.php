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
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('operation_key')->nullable()->unique();
            $table->string('gcash_reference', 50)->nullable();
            $table->index(['user_id', 'posted_at']);
        });
        Schema::create('payment_refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_entry_id')->constrained('payment_entries')->restrictOnDelete();
            $table->foreignId('deposit_entry_id')->constrained('payment_entries')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unique(['refund_entry_id', 'deposit_entry_id']);
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refund_allocations');
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropUnique(['operation_key']);
            $table->dropIndex(['user_id', 'posted_at']);
            $table->dropColumn(['order_id', 'operation_key', 'gcash_reference']);
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['auditable_type', 'auditable_id', 'event']);
        });
    }
};
