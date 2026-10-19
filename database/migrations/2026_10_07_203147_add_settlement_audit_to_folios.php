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
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('overtime_hours')->default(0);
            $table->string('loss_slip_reference')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropUnique(['loss_slip_reference']);
            $table->dropForeign(['checked_out_by']);
            $table->dropColumn(['checked_out_by', 'overtime_hours', 'loss_slip_reference']);
        });
    }
};
