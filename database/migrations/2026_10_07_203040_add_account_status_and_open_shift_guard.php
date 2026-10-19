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
        if (DB::table('shifts')->whereNull('closed_at')->count() > 1) {
            throw new RuntimeException('Reconcile multiple open shifts before upgrading.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        Schema::table('shifts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('open_key')->nullable()->unique();
        });
        DB::table('shifts')->whereNull('closed_at')->update(['open_key' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropUnique(['open_key']);
            $table->dropColumn('open_key');
        });
    }
};
