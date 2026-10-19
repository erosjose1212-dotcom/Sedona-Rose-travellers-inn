<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\InventoryEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestOrderService
{
    public function __construct(
        private FinancialPostingService $posting,
        private InventoryService $inventory,
        private AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function add(Request $request, Folio $folio, array $data): array
    {
        $quantities = [];
        foreach ($data['items'] ?? [['id' => $data['pos_item_id'], 'qty' => $data['quantity']]] as $line) {
            $id = (int) $line['id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $line['qty'];
        }

        return $this->posting->execute($request, 'pos.addon', $folio->id, $data, function () use ($folio, $quantities, $request): array {
            $room = Room::whereKey($folio->room_id)->lockForUpdate()->firstOrFail();
            $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive() || $locked->room_id !== $room->id) {
                throw new DomainException('Add-ons require an active folio in the selected room. Refresh the dashboard.');
            }
            if (! $room->is_staff_quarters && ! $room->isOccupied()) {
                throw new DomainException('Add-ons require an occupied guest room.');
            }
            $number = 'ORD-'.strtoupper(substr((string) Str::uuid(), 0, 13));
            $items = $this->inventory->consumeStockForOrder($quantities, 'quick_addon', $number, $request->user()->id, 'Guest add-ons');
            $totalCents = $items->sum(fn (PosItem $item): int => BillingService::cents($item->price) * $quantities[$item->id]);
            $kitchenOrder = $items->contains(fn (PosItem $item): bool => $item->kitchen_hours_only);
            $order = Order::create([
                'user_id' => $request->user()->id, 'folio_id' => $locked->id, 'room_id' => $room->id,
                'transaction_id' => $number, 'type' => 'room_charge', 'status' => $kitchenOrder ? 'new' : 'delivered',
                'payment_method' => 'charge_to_room', 'total' => $totalCents / 100,
                'dispatched_at' => $kitchenOrder ? null : now(),
            ]);
            $payload = $locked->pos_items ?? [];
            foreach ($items as $item) {
                $quantity = $quantities[$item->id];
                $subtotal = BillingService::cents($item->price) * $quantity / 100;
                $line = $order->items()->create([
                    'pos_item_id' => $item->id, 'item_name' => $item->name, 'unit_price' => $item->price,
                    'quantity' => $quantity, 'subtotal' => $subtotal,
                ]);
                $payload[] = [
                    'name' => $item->name, 'qty' => $quantity, 'price' => (float) $item->price, 'subtotal' => $subtotal,
                    'order_id' => $order->id, 'order_item_id' => $line->id,
                ];
            }
            $locked->pos_items = $payload;
            $locked->recalculate();
            $this->audit->record($request, 'pos.room_charge', $order, ['folio_id' => $locked->id, 'total' => $order->total, 'operation_key' => $request->input('operation_key')]);

            return ['order_id' => $order->id, 'room_number' => $room->number];
        });
    }

    /** @param array<string, mixed> $data */
    public function correct(Request $request, Folio $folio, OrderItem $item, array $data): void
    {
        Gate::authorize('review-financial');
        $this->posting->execute($request, 'pos.correct', $item->id, $data, function () use ($folio, $item, $data, $request): array {
            Room::whereKey($folio->room_id)->lockForUpdate()->firstOrFail();
            $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isActive(), 409, 'Settled stays cannot be corrected.');
            abort_unless((int) $locked->room_id === (int) $folio->room_id, 409, 'Room assignment changed. Reload this stay.');
            $order = Order::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->folio_id === $locked->id && $order->type === 'room_charge', 404);
            $line = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $line->quantity === (int) $data['expected_quantity'], 409, 'This order changed. Reload before correcting it.');
            $quantity = (int) $data['quantity'];
            if ($quantity > (int) $line->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Add additional items as a new order.']);
            }
            $before = $line->only(['quantity', 'unit_price', 'subtotal']);
            $index = $this->findPayloadIndex($locked->pos_items ?? [], $line);
            abort_if($index === null, 409, 'The saved bill does not match this order. Review the folio before correcting it.');
            $removed = (int) $line->quantity - $quantity;
            if ($removed === 0) {
                return ['order_item_id' => $line->id];
            }
            $returnedStock = $order->status === 'new';
            if ($returnedStock) {
                $this->inventory->returnUnpreparedOrderStock($order, $line, $removed, $request->user(), $data['reason']);
            }
            $line->quantity = $quantity;
            $line->subtotal = BillingService::cents($line->unit_price) * $quantity / 100;
            $line->save();
            $payload = $locked->pos_items;
            $payload[$index]['qty'] = $quantity;
            $payload[$index]['subtotal'] = (float) $line->subtotal;
            $payload[$index]['order_id'] = $order->id;
            $payload[$index]['order_item_id'] = $line->id;
            $locked->pos_items = $payload;
            $order->recalculateTotal();
            if ((int) $order->items()->sum('quantity') === 0) {
                $order->update(['status' => 'cancelled']);
            }
            $locked->recalculate();
            $this->audit->record($request, 'pos.order_corrected', $line, [
                'folio_id' => $locked->id, 'order_id' => $order->id,
                'before' => $before, 'after' => $line->only(array_keys($before)),
                'reason' => $data['reason'], 'stock_returned' => $returnedStock,
                'operation_key' => $request->input('operation_key'),
            ]);

            return ['order_item_id' => $line->id];
        });
    }

    /** @param array{reason: string} $data */
    public function cancel(Request $request, Folio $folio, Order $order, array $data): void
    {
        abort_unless($request->user()->can('manage_folios'), 403);
        abort_unless((int) $order->folio_id === $folio->id, 404);
        $this->posting->execute($request, 'pos.cancel', $order->id, $data, function () use ($request, $folio, $order, $data): array {
            $room = Room::whereKey($folio->room_id)->lockForUpdate()->firstOrFail();
            $lockedFolio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedOrder->folio_id === $lockedFolio->id, 404);
            abort_unless((int) $lockedFolio->room_id === $room->id, 409, 'Room assignment changed. Reload this stay.');
            if (! $lockedFolio->isActive() || $lockedOrder->type !== 'room_charge' || ! $lockedOrder->isNew()) {
                throw ValidationException::withMessages(['cancel' => 'Only unprepared, unpaid room orders on an active stay can be cancelled.']);
            }
            if (! $lockedOrder->isWithinCancellationGrace()) {
                throw ValidationException::withMessages(['cancel' => 'The 15-minute cancellation window from order creation has expired.']);
            }
            if (BillingService::cents($lockedOrder->cash_tendered) > 0 || BillingService::cents($lockedOrder->gcash_amount) > 0
                || PaymentEntry::where('order_id', $lockedOrder->id)->where(fn ($query) => $query->where('cash_amount', '>', 0)->orWhere('gcash_amount', '>', 0))->exists()) {
                throw ValidationException::withMessages(['cancel' => 'Paid orders require the payment/refund review workflow.']);
            }
            $lines = $lockedOrder->items()->orderBy('id')->lockForUpdate()->get();
            $before = ['status' => $lockedOrder->status, 'total' => $lockedOrder->total, 'items' => $lines->map(fn (OrderItem $line): array => $line->only(['id', 'item_name', 'quantity', 'unit_price', 'subtotal']))->all()];
            $stockIds = InventoryEvent::where('reference_id', $lockedOrder->transaction_id)->where('quantity_change', '<', 0)->pluck('pos_item_id');
            PosItem::whereIn('id', $stockIds)->orderBy('id')->lockForUpdate()->get();
            $payload = $lockedFolio->pos_items ?? [];
            foreach ($lines as $line) {
                if ((int) $line->quantity === 0) {
                    continue;
                }
                $index = $this->findPayloadIndex($payload, $line);
                abort_if($index === null, 409, 'The saved bill does not match this order. Review the folio before cancelling it.');
                $this->inventory->returnUnpreparedOrderStock($lockedOrder, $line, (int) $line->quantity, $request->user(), $data['reason']);
                $payload[$index]['qty'] = 0;
                $payload[$index]['subtotal'] = 0;
                $payload[$index]['order_id'] = $lockedOrder->id;
                $payload[$index]['order_item_id'] = $line->id;
                $line->update(['quantity' => 0, 'subtotal' => 0]);
            }
            $lockedOrder->update(['status' => 'cancelled', 'total' => 0]);
            $lockedFolio->pos_items = $payload;
            $lockedFolio->recalculate();
            $this->audit->record($request, 'pos.order_cancelled', $lockedOrder, [
                'folio_id' => $lockedFolio->id, 'before' => $before, 'after' => ['status' => 'cancelled', 'total' => 0],
                'reason' => $data['reason'], 'stock_returned' => true, 'created_at' => $lockedOrder->created_at->toIso8601String(),
                'cancelled_at' => now()->toIso8601String(), 'operation_key' => $request->input('operation_key'),
            ]);

            return ['order_id' => $lockedOrder->id];
        });
    }

    /** @return list<array<string, mixed>> */
    public function legacyItems(Folio $folio): array
    {
        $folio->loadMissing('orders.items');
        $remaining = $folio->pos_items ?? [];
        foreach ($folio->orders as $order) {
            foreach ($order->items as $line) {
                $index = $this->findPayloadIndex($remaining, $line);
                if ($index !== null) {
                    unset($remaining[$index]);
                }
            }
        }

        return array_values($remaining);
    }

    /** @param array<int, array<string, mixed>> $payload */
    private function findPayloadIndex(array $payload, OrderItem $line): ?int
    {
        foreach ($payload as $index => $entry) {
            if (isset($entry['order_item_id'])) {
                if ((int) $entry['order_item_id'] === $line->id) {
                    return $index;
                }

                continue;
            }
            if (($entry['name'] ?? '') === $line->item_name
                && (int) ($entry['qty'] ?? 0) === (int) $line->quantity
                && BillingService::cents($entry['price'] ?? 0) === BillingService::cents($line->unit_price)
                && BillingService::cents($entry['subtotal'] ?? 0) === BillingService::cents($line->subtotal)) {
                return $index;
            }
        }

        return null;
    }
}
