<?php

namespace App\Models;

use App\Services\BillingService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Folio extends Model
{
    protected $fillable = [
        'room_id', 'guest_id', 'user_id', 'booking_id',
        'transaction_id', 'receipt_number', 'receipt_issued_at', 'rate_tier', 'stay_mode',
        'checked_in_at', 'expected_checkout_at', 'checked_out_at',
        'status',
        'extra_persons', 'extra_bedding', 'extra_towels', 'extra_hours',
        'pos_items',
        'room_charge', 'surcharge_total', 'pos_total',
        'security_deposit', 'deposit_payment_method', 'deposit_status',
        'deposit_collected_at', 'deposit_refunded_at', 'deposit_refunded_amount',
        'gross_total', 'discount_amount', 'discount_type', 'discount_id_ref', 'senior_pwd_discount', 'net_total',
        'discount_schedule', 'discount_override_amount',
        'payment_method', 'cash_tendered', 'gcash_amount',
        'gcash_reference', 'change_due',
        'force_requested_by', 'force_checkout', 'force_reason', 'force_by', 'checked_out_by', 'overtime_hours', 'loss_slip_reference',
    ];

    protected $casts = [
        'pos_items' => 'array',
        'discount_schedule' => 'array',
        'discount_override_amount' => 'decimal:2',
        'checked_in_at' => 'datetime',
        'expected_checkout_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'receipt_issued_at' => 'datetime',
        'billing_printed_at' => 'datetime',
        'deposit_collected_at' => 'datetime',
        'deposit_refunded_at' => 'datetime',
        'senior_pwd_discount' => 'boolean',
        'force_checkout' => 'boolean',
        'room_charge' => 'decimal:2',
        'surcharge_total' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'deposit_refunded_amount' => 'decimal:2',
        'pos_total' => 'decimal:2',
        'gross_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_total' => 'decimal:2',
        'cash_tendered' => 'decimal:2',
        'gcash_amount' => 'decimal:2',
        'change_due' => 'decimal:2',
    ];

    // ── Relationships ────────────────────────────────────────────────

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function forcedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'force_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function paymentEntries(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isOpenTime(): bool
    {
        return $this->stay_mode === 'open';
    }

    public function isStayTimeLocked(): bool
    {
        return $this->billing_printed_at !== null;
    }

    public function isStayTimeLockedFor(?User $actor): bool
    {
        return $this->isStayTimeLocked() && ! ($actor?->hasAnyRole(['admin', 'owner']) ?? false);
    }

    public function assertStayTimeEditable(?User $actor = null): void
    {
        if ($this->isStayTimeLockedFor($actor)) {
            throw ValidationException::withMessages(['stay_time' => 'Statement / Billing was issued for printing. Check-in time, stay extensions and package conversions are locked for this stay.']);
        }
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isWithinCancellationGrace(): bool
    {
        return $this->created_at !== null && now()->gte($this->created_at)
            && now()->lte($this->created_at->copy()->addMinutes(15));
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active' && ! $this->isOpenTime() && now()->gt($this->expected_checkout_at);
    }

    public function remainingSeconds(): int
    {
        if (! $this->isActive()) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->expected_checkout_at, false));
    }

    /**
     * Recalculate all totals and persist them.
     * Call after any surcharge or POS addition.
     */
    public function recalculate(): void
    {
        if (! $this->isActive()) {
            return;
        }
        $this->fill(app(BillingService::class)->calculate($this, includeOvertime: false)['attributes']);
        $this->save();
    }

    /**
     * Allocate a stay reference independently of the checkout receipt sequence.
     */
    public static function generateTransactionId(): string
    {
        return self::allocateDailyReference('stay', 'STAY', 'transaction_id', now());
    }

    /**
     * Issue the receipt inside the same transaction that settles the stay.
     */
    public function issueReceipt(): void
    {
        $locked = DB::transaction(function (): self {
            $folio = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            abort_unless($folio->status === 'checked_out' && $folio->checked_out_at !== null && ! $folio->room->is_staff_quarters, 409, 'Complete guest checkout before issuing a receipt.');
            if ($folio->receipt_number === null) {
                $issuedAt = now();
                $folio->fill([
                    'receipt_number' => self::allocateDailyReference('scti', 'SCTI', 'receipt_number', $issuedAt),
                    'receipt_issued_at' => $issuedAt,
                ])->save();
            }

            return $folio;
        }, 3);
        $this->setRawAttributes($locked->getAttributes(), true);
    }

    public function receiptReference(): ?string
    {
        return $this->receipt_number ?? ($this->status === 'checked_out' && str_starts_with($this->transaction_id ?? '', 'SCTI-') ? $this->transaction_id : null);
    }

    private static function allocateDailyReference(string $namespace, string $prefix, string $column, CarbonInterface $at): string
    {
        return DB::transaction(function () use ($namespace, $prefix, $column, $at): string {
            $date = $at->copy()->setTimezone('Asia/Manila')->format('Ymd');
            $sequenceName = $namespace.':'.$date;
            DB::table('document_sequences')->insertOrIgnore(['name' => $sequenceName, 'last_number' => 0]);
            $sequence = DB::table('document_sequences')->where('name', $sequenceName)->lockForUpdate()->first();
            $number = (int) $sequence->last_number;
            do {
                $number++;
                $id = $prefix.'-'.$date.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            } while (self::where($column, $id)->orWhere('transaction_id', $id)->exists());
            DB::table('document_sequences')->where('name', $sequenceName)->update(['last_number' => $number]);

            return $id;
        }, 3);
    }

    public function dailySequenceNumber(): ?int
    {
        return preg_match('/^(?:STAY|SCTI)-\d{8}-(\d+)$/D', $this->transaction_id ?? '', $match) ? (int) $match[1] : null;
    }
}
