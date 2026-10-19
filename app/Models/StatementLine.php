<?php

namespace App\Models;

use Database\Factories\StatementLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatementLine extends Model
{
    /** @use HasFactory<StatementLineFactory> */
    use HasFactory;

    protected $fillable = ['provider', 'import_hash', 'fingerprint', 'reference', 'posted_at', 'business_date', 'gross_amount', 'fee_amount', 'net_amount', 'user_id'];

    protected $casts = ['posted_at' => 'datetime', 'business_date' => 'date', 'gross_amount' => 'decimal:2', 'fee_amount' => 'decimal:2', 'net_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Posted records are immutable.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Posted records cannot be deleted.');
        });
    }
}
