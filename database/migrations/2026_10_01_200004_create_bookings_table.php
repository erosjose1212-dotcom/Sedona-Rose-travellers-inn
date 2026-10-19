<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // staff who booked
            $table->string('guest_name');
            $table->string('guest_contact', 20)->nullable();
            $table->unsignedTinyInteger('headcount')->default(1);
            $table->enum('rate_tier', ['3h', '6h', '12h', '24h', 'promo']);
            $table->dateTime('arrival_at');
            $table->dateTime('departure_at');
            $table->enum('status', ['scheduled', 'checked_in', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
