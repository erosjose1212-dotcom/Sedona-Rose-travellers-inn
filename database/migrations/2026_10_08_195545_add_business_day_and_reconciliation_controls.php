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
        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->string('status')->default('open');
            $table->unsignedInteger('version')->default(0);
            $table->json('snapshot')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('business_day_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_day_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('sha256', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['business_day_id', 'version']);
        });
        Schema::create('statement_lines', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('import_hash', 64)->index();
            $table->string('fingerprint', 64)->unique();
            $table->string('reference', 100);
            $table->timestamp('posted_at');
            $table->date('business_date')->index();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('statement_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('statement_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_exclusions');
        Schema::dropIfExists('statement_matches');
        Schema::dropIfExists('statement_lines');
        Schema::dropIfExists('business_day_snapshots');
        Schema::dropIfExists('business_days');
    }
};
