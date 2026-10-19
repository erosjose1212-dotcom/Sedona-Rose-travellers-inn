<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->date('week_start');  // Monday
            $table->date('week_end');    // Sunday
            $table->boolean('is_frozen')->default(false);
            $table->decimal('total_room_revenue',    10, 2)->default(0);
            $table->decimal('total_kitchen_revenue', 10, 2)->default(0);
            $table->decimal('total_gross_revenue',   10, 2)->default(0);
            $table->decimal('total_gcash',           10, 2)->default(0);
            $table->decimal('total_cash',            10, 2)->default(0);
            // Two-column expense tracking
            $table->json('operating_expenses')->nullable();   // [{label, amount}]
            $table->json('management_allowances')->nullable();// [{label, amount}]
            $table->decimal('total_expenses',        10, 2)->default(0);
            $table->decimal('net_profit',            10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
