<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'shift_id', 'folio_id', 'room_id', 'transaction_id',
        'type', 'status', 'special_instructions', 'total',
        'payment_method', 'cash_tendered', 'gcash_amount',
        'gcash_reference', 'change_due', 'dispatched_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'cash_tendered' => 'decimal:2',
        'gcash_amount' => 'decimal:2',
        'change_due' => 'decimal:2',
        'dispatched_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function isWithinCancellationGrace(): bool
    {
        return $this->created_at !== null && now()->gte($this->created_at)
            && now()->lte($this->created_at->copy()->addMinutes(15));
    }

    public function isPreparing(): bool
    {
        return $this->status === 'preparing';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function recalculateTotal(): void
    {
        $this->total = $this->items()->sum('subtotal');
        $this->save();
    }
}
