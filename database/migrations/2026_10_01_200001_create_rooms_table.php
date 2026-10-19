<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20);                  // e.g. "12 A", "12 B", "1", "13"
            $table->unsignedTinyInteger('floor');           // 1, 2, 3
            $table->string('name', 100);                    // e.g. "Room 101" / "Presidential Suite 302"
            $table->string('type', 50);                     // Standard, Deluxe, Suite, etc.
            $table->enum('status', [
                'available', 'occupied', 'maintenance'
            ])->default('available');
            $table->boolean('is_staff_quarters')->default(false); // Room 12 flag
            $table->decimal('base_rate_3h',  8, 2)->default(0);
            $table->decimal('base_rate_6h',  8, 2)->default(0);
            $table->decimal('base_rate_12h', 8, 2)->default(0);
            $table->decimal('base_rate_24h', 8, 2)->default(0);
            $table->decimal('base_rate_promo', 8, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
