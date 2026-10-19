<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('id_type', 50)->nullable();      // Passport, Driver's License, etc.
            $table->string('id_number', 100)->nullable();
            $table->unsignedTinyInteger('headcount')->default(1);
            $table->boolean('is_senior')->default(false);
            $table->boolean('is_pwd')->default(false);
            $table->string('contact', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
