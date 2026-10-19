<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('status', ['new', 'preparing', 'ready', 'delivered', 'cancelled'])->default('new')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->where('status', 'cancelled')->exists()) {
            throw new RuntimeException('Cancelled orders must be preserved. Use a forward migration instead.');
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('status', ['new', 'preparing', 'ready', 'delivered'])->default('new')->change();
        });
    }
};
