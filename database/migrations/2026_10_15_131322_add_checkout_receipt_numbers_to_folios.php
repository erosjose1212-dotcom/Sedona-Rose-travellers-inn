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
        Schema::table('folios', function (Blueprint $table) {
            $table->string('receipt_number', 64)->nullable()->unique();
            $table->dateTime('receipt_issued_at')->nullable()->index();
        });

        DB::transaction(function (): void {
            DB::table('folios')->where('status', 'checked_out')->whereNotNull('checked_out_at')->whereNotNull('transaction_id')
                ->whereIn('room_id', DB::table('rooms')->select('id')->where('is_staff_quarters', false))
                ->update(['receipt_number' => DB::raw('transaction_id'), 'receipt_issued_at' => DB::raw('checked_out_at')]);
            $highest = [];
            DB::table('folios')->select('id', 'transaction_id')->chunkById(500, function ($folios) use (&$highest): void {
                foreach ($folios as $folio) {
                    if (preg_match('/^SCTI-(\d{8})-(\d+)$/D', $folio->transaction_id ?? '', $match)) {
                        $highest[$match[1]] = max($highest[$match[1]] ?? 0, (int) $match[2]);
                    }
                }
            });
            foreach ($highest as $date => $number) {
                $name = 'scti:'.$date;
                DB::table('document_sequences')->insertOrIgnore(['name' => $name, 'last_number' => 0]);
                $sequence = DB::table('document_sequences')->where('name', $name)->lockForUpdate()->first();
                DB::table('document_sequences')->where('name', $name)->update(['last_number' => max((int) $sequence->last_number, $number)]);
            }
        });
    }

    /**
     * Rolling back removes receipt metadata; sequence reservations remain to prevent number reuse.
     */
    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropIndex(['receipt_issued_at']);
            $table->dropColumn(['receipt_number', 'receipt_issued_at']);
        });
    }
};
