<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'opened_by', 'closed_by', 'shift_type', 'shift_date',
        'opened_at', 'closed_at', 'is_frozen',
        'room_revenue', 'kitchen_revenue', 'gross_revenue',
        'denomination_count', 'cash_total', 'gcash_total',
        'handoff_notes', 'opening_float', 'total_expenses', 'expected_cash', 'cash_variance', 'open_key',
        'incoming_user_id', 'accepted_by', 'handover_from_id', 'handover_status', 'accepted_cash', 'handover_variance', 'accepted_at',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_frozen' => 'boolean',
        'denomination_count' => 'array',
        'room_revenue' => 'decimal:2',
        'kitchen_revenue' => 'decimal:2',
        'gross_revenue' => 'decimal:2',
        'cash_total' => 'decimal:2',
        'gcash_total' => 'decimal:2',
        'opening_float' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'cash_variance' => 'decimal:2',
        'accepted_cash' => 'decimal:2', 'handover_variance' => 'decimal:2', 'accepted_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    protected static function booted(): void
    {
        static::saving(function (Shift $shift): void {
            $shift->open_key = $shift->closed_at === null ? 1 : null;
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return is_null($this->closed_at);
    }

    /**
     * Determine shift type (day/night) from a given datetime.
     */
    public static function typeForTime(Carbon $time): string
    {
        $hour = (int) $time->format('G');

        return ($hour >= 6 && $hour < 18) ? 'day' : 'night';
    }
}
