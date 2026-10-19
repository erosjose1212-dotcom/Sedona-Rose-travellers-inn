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
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('configuration');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('accounting_settings')->insert(['id' => 1, 'configuration' => json_encode(['cutoff_hour' => 6, 'timezone' => 'Asia/Manila', 'invoice_enabled' => false, 'export_enabled' => false, 'tax_mode' => 'unconfigured', 'tax_rate_bps' => null, 'account_mappings' => [], 'destination' => 'Accountant CSV'])]);
        Schema::create('accounting_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_day_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('destination', 100);
            $table->string('status')->default('draft');
            $table->json('lines');
            $table->json('configuration');
            $table->string('sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_reference', 255)->nullable();
            $table->foreignId('correction_of_id')->nullable()->constrained('accounting_exports')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_day_id', 'version', 'destination']);
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('number')->unique();
            $table->date('business_date')->index();
            $table->json('document');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('accounting_exports');
        Schema::dropIfExists('accounting_settings');
    }
};
