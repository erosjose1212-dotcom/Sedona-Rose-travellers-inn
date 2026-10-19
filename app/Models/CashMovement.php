<?php

namespace App\Models;

use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    protected $fillable = ['shift_id', 'user_id', 'approval_request_id', 'operation_key', 'kind', 'destination', 'amount', 'reference', 'business_date', 'posted_at'];

    protected $casts = ['amount' => 'decimal:2', 'business_date' => 'date', 'posted_at' => 'datetime'];

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
