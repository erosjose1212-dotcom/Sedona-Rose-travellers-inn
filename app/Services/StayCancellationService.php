<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Invoice;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StayCancellationService
{
    public function __construct(private FinancialPostingService $posting, private AuditService $audit) {}

    public function unavailableReason(Folio $folio): ?string
    {
        $folio->loadMissing('room', 'orders.items');
        if (! $folio->isActive() || $folio->room->is_staff_quarters) {
            return 'Only active guest stays can be cancelled.';
        }
        if (! $folio->isWithinCancellationGrace()) {
            return 'The 15-minute cancellation window from creation has expired.';
        }
        if (BillingService::cents($folio->security_deposit) > 0 || BillingService::cents($folio->cash_tendered) > 0 || BillingService::cents($folio->gcash_amount) > 0
            || $folio->paymentEntries()->where(fn ($query) => $query->where('cash_amount', '>', 0)->orWhere('gcash_amount', '>', 0))->exists()
            || Invoice::where('folio_id', $folio->id)->exists()) {
            return 'Money has been recorded on this stay. Use the payment/refund review workflow.';
        }
        if ($folio->orders->contains(fn ($order): bool => $order->items->contains(fn ($line): bool => (int) $line->quantity > 0))
            || collect($folio->pos_items ?? [])->contains(fn ($item): bool => (int) ($item['qty'] ?? 1) > 0 || BillingService::cents($item['subtotal'] ?? 0) > 0)) {
            return 'This stay has existing orders. Cancel eligible unprepared orders first; other charges require admin review.';
        }

        return null;
    }

    /** @param array{reason: string} $data */
    public function cancel(Request $request, Folio $folio, array $data): void
    {
        abort_unless($request->user()->hasRole('admin') && $request->user()->can('manage_folios'), 403);
        $this->posting->execute($request, 'folio.cancel', $folio->id, $data, function () use ($request, $folio, $data): array {
            $room = Room::whereKey($folio->room_id)->lockForUpdate()->firstOrFail();
            $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->room_id === $room->id, 409, 'Room assignment changed. Reload the stay.');
            $locked->setRelation('room', $room);
            if ($reason = $this->unavailableReason($locked)) {
                throw ValidationException::withMessages(['cancel' => $reason]);
            }
            $before = $locked->only(['status', 'room_charge', 'extra_hours', 'extra_persons', 'extra_bedding', 'extra_towels', 'overtime_hours', 'surcharge_total', 'pos_total', 'gross_total', 'discount_amount', 'net_total', 'checked_in_at', 'checked_out_at', 'checked_out_by']);
            $locked->fill([
                'status' => 'voided', 'room_charge' => 0, 'surcharge_total' => 0, 'pos_total' => 0, 'gross_total' => 0, 'discount_amount' => 0, 'net_total' => 0,
                'extra_hours' => 0, 'extra_persons' => 0, 'extra_bedding' => 0, 'extra_towels' => 0, 'overtime_hours' => 0,
                'checked_out_at' => now(), 'checked_out_by' => $request->user()->id,
            ])->save();
            $room->update(['status' => 'available']);
            if ($locked->booking_id) {
                Booking::whereKey($locked->booking_id)->where('status', 'checked_in')->lockForUpdate()->first()?->update(['status' => 'cancelled']);
            }
            $this->audit->record($request, 'folio.cancelled', $locked, [
                'before' => $before, 'after' => $locked->only(array_keys($before)), 'reason' => $data['reason'],
                'transaction_id' => $locked->transaction_id, 'created_at' => $locked->created_at->toIso8601String(),
                'cancelled_at' => now()->toIso8601String(), 'operation_key' => $request->input('operation_key'),
            ]);

            return ['folio_id' => $locked->id];
        });
    }
}
