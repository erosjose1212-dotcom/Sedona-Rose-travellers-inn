<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        $highest = 0;
        DB::table('folios')->select('id', 'transaction_id')->chunkById(500, function ($folios) use (&$highest): void {
            foreach ($folios as $folio) {
                if (preg_match('/^SCTI-(\d+)$/', $folio->transaction_id ?? '', $match)) {
                    $highest = max($highest, (int) $match[1]);
                }
            }
        });
        DB::table('document_sequences')->insert(['name' => 'scti', 'last_number' => $highest]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
