<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('shift_type', ['day', 'night']); // DAY=6AM-6PM, NIGHT=6PM-6AM
            $table->date('shift_date');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->boolean('is_frozen')->default(false); // admin lock
            $table->decimal('room_revenue',    10, 2)->default(0);
            $table->decimal('kitchen_revenue', 10, 2)->default(0);
            $table->decimal('gross_revenue',   10, 2)->default(0);
            // Cash drawer denomination counts (PHP banknotes/coins)
            $table->json('denomination_count')->nullable();
            $table->decimal('cash_total',  10, 2)->default(0);
            $table->decimal('gcash_total', 10, 2)->default(0);
            $table->text('handoff_notes')->nullable();
            $table->timestamps();
        });

        // Shift handoff tasks
        Schema::create('shift_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_tasks');
        Schema::dropIfExists('shifts');
    }
};
