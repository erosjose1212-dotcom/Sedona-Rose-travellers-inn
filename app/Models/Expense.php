<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Expense extends Model
{
    protected $fillable = [
        'user_id',
        'shift_id',
        'voucher_number',
        'expense_date',
        'category',
        'description',
        'amount',
        'payment_source',
        'receipt_reference',
        'status',
        'notes',
        'reversal_of_id', 'approval_request_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateVoucherNumber(): string
    {
        return 'EXP-'.now()->format('Ymd').'-'.strtoupper(Str::random(12));
    }
}
