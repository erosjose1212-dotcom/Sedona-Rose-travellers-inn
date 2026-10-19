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
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('payload');
            $table->string('source_hash', 64);
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->string('evidence_reference', 255)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['requested_by', 'created_at']);
        });
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('kind');
            $table->string('destination');
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100);
            $table->date('business_date');
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->index(['business_date', 'kind']);
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('incoming_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('handover_from_id')->nullable()->unique()->constrained('shifts')->restrictOnDelete();
            $table->string('handover_status')->nullable();
            $table->decimal('accepted_cash', 12, 2)->nullable();
            $table->decimal('handover_variance', 12, 2)->nullable();
            $table->timestamp('accepted_at')->nullable();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('expenses')->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->constrained('payment_entries')->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('business_date')->nullable()->index();
        });
        Schema::table('folios', function (Blueprint $table) {
            $table->foreignId('force_requested_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folios', fn (Blueprint $table) => $table->dropConstrainedForeignId('force_requested_by'));
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('approval_request_id');
            $table->dropIndex(['business_date']);
            $table->dropColumn('business_date');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropUnique(['reversal_of_id']);
            $table->dropColumn('reversal_of_id');
            $table->dropConstrainedForeignId('approval_request_id');
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['handover_from_id']);
            $table->dropUnique(['handover_from_id']);
            $table->dropColumn('handover_from_id');
            foreach (['incoming_user_id', 'accepted_by'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['handover_status', 'accepted_cash', 'handover_variance', 'accepted_at']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('approval_requests');
    }
};
