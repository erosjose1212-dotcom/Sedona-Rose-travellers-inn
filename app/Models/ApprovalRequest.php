<?php

namespace App\Models;

use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    protected $fillable = ['kind', 'source_type', 'source_id', 'requested_by', 'reviewed_by', 'executed_by', 'payload', 'source_hash', 'status', 'reason', 'evidence_reference', 'review_note', 'reviewed_at', 'executed_at'];

    protected $casts = ['payload' => 'array', 'reviewed_at' => 'datetime', 'executed_at' => 'datetime'];

    public const KIND_LABELS = [
        'folio_override' => 'Room rate / custom discount', 'invoice_tax_treatment' => 'Invoice tax basis',
        'deposit_refund' => 'Guest deposit refund', 'pos_refund' => 'Walk-in sale refund',
        'statement_void' => 'Statement import correction', 'cash_drop' => 'Cash transfer to safe',
        'float_addition' => 'Drawer float addition', 'bank_deposit' => 'Cash deposit to bank',
        'stock_adjustment' => 'Stock adjustment', 'stock_recount' => 'Stock recount',
        'expense_review' => 'Paid expense review', 'expense_reversal' => 'Expense return',
        'write_off' => 'Force checkout / unpaid bill', 'handover_variance' => 'Handover variance',
        'shift_variance' => 'Drawer cash variance',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? ucfirst(str_replace('_', ' ', $this->kind));
    }

    public function permitsOwnerSelfReview(?User $actor): bool
    {
        return $this->kind === 'folio_override' && $actor !== null
            && $this->requested_by === $actor->id && $actor->hasRole('owner');
    }

    public function isStockChange(): bool
    {
        return in_array($this->kind, ['stock_adjustment', 'stock_recount'], true);
    }

    public function sourceLabel(): string
    {
        $source = $this->source;

        return match (true) {
            $source instanceof Folio => 'Room '.($source->room?->number ?? '?').' — '.($source->guest?->name ?? 'Guest').' · '.$source->transaction_id,
            $source instanceof PosItem => $source->name,
            $source instanceof Expense => $source->voucher_number.' — '.$source->description,
            $source instanceof PaymentEntry => ($source->order?->transaction_id ?? 'Payment #'.$source->id).($source->order?->room ? ' · Room '.$source->order->room->number : ''),
            $source instanceof StatementLine => $source->provider.' · '.$source->reference,
            $source instanceof Shift => 'Drawer #'.$source->id,
            default => class_basename($this->source_type).' #'.$this->source_id.' (unavailable)',
        };
    }

    /** @param array<int, string> $stockNames
     * @return array<string, string>
     */
    public function changeDetails(array $stockNames = []): array
    {
        $labels = ['amount' => 'Amount', 'room_charge' => 'New room charge', 'discount_override_amount' => 'Custom discount', 'taxable_amount' => 'Taxable amount',
            'discount_type' => 'Discount', 'discount_id_ref' => 'Card / ID reference', 'stock_quantity' => 'New stock quantity', 'payment_method' => 'Payment method',
            'gcash_reference' => 'GCash reference', 'destination' => 'Destination / source', 'reference' => 'Reference', 'reason' => 'Stock change reason', 'counts' => 'Stock counts'];
        $details = [];
        foreach ($this->payload ?? [] as $key => $value) {
            $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
            if ($key === 'counts' && is_array($value)) {
                foreach ($value as $id => $quantity) {
                    $details['Stock: '.($stockNames[$id] ?? 'Item #'.$id)] = (string) $quantity;
                }
            } elseif (in_array($key, ['amount', 'room_charge', 'discount_override_amount', 'taxable_amount'], true)) {
                $details[$label] = '₱'.number_format((float) $value, 2);
            } else {
                $details[$label] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;
            }
        }

        return $details;
    }
}
