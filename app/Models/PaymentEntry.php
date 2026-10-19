<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEntry extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = ['folio_id', 'order_id', 'shift_id', 'user_id', 'operation_key', 'kind', 'cash_amount', 'gcash_amount', 'gcash_reference', 'posted_at', 'business_date', 'reversal_of_id', 'approval_request_id'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Posted payments are immutable. Record a linked correction instead.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Posted payments cannot be deleted.');
        });
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected $casts = ['cash_amount' => 'decimal:2', 'gcash_amount' => 'decimal:2', 'posted_at' => 'datetime'];
}
