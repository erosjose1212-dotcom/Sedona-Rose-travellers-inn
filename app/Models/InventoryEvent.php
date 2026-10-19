<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryEvent extends Model
{
    protected $table = 'inventory_events';

    protected $fillable = [
        'pos_item_id',
        'item_name',
        'event_type',
        'quantity_change',
        'balance_after',
        'shift_id',
        'reference_id',
        'user_id',
        'operator_name',
        'notes',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'balance_after'   => 'integer',
    ];

    public function posItem(): BelongsTo
    {
        return $this->belongsTo(PosItem::class, 'pos_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
