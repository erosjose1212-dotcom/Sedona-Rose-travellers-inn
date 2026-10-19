<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosItem;
use App\Models\Room;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\FinancialPostingService;
use App\Services\GuestOrderService;
use App\Services\InventoryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PosController extends Controller
{
    public function __construct(private FinancialPostingService $posting, private AuditService $audit) {}

    public function index(Request $request): View
    {
        $selectedCategory = $request->query('category', 'all');

        $categories = PosItem::notRetired()->select('category')->distinct()->pluck('category');

        $query = PosItem::available();
        if ($selectedCategory !== 'all') {
            $query->where('category', $selectedCategory);
        }
        $posItems = $query->get();

        $occupiedRooms = Room::with('activeFolio.guest')
            ->where(function ($q) {
                $q->where('status', 'occupied')
                    ->orWhere('is_staff_quarters', true);
            })
            ->orderByNumber()
            ->get();

        foreach ($occupiedRooms as $occ) {
            if ($occ->is_staff_quarters && ! $occ->activeFolio) {
                $occ->getOrCreateStaffFolio();
                $occ->load('activeFolio.guest');
            }
        }

        $recentOrders = Order::with(['room', 'folio.guest', 'items'])
            ->latest()
            ->take(15)
            ->get();

        return view('pos.index', compact('posItems', 'categories', 'selectedCategory', 'occupiedRooms', 'recentOrders'));
    }

    /**
     * Add Quick Add-On to Room Folio (from Dashboard Add On button).
     */
    public function addQuickAddOn(Request $request, Folio $folio, GuestOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'items' => 'required_without:pos_item_id|array|min:1|max:100',
            'items.*' => 'array:id,qty',
            'items.*.id' => 'required|integer|exists:pos_items,id',
            'items.*.qty' => 'required|integer|min:1|max:65535',
            'pos_item_id' => 'required_without:items|integer|exists:pos_items,id',
            'quantity' => 'required_with:pos_item_id|integer|min:1|max:65535',
        ]);
        try {
            $result = $orders->add($request, $folio, $data);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()->route('folios.orders.index', ['folio' => $folio, 'panel' => $request->boolean('panel') ? 1 : null])
            ->with('success', 'Add-ons saved to Room '.$result['room_number'].'.');
    }

    /**
     * Show Printable Order Slip.
     */
    public function showOrderSlip(Folio $folio): View
    {
        $folio->load(['room', 'guest', 'orders.items']);

        return view('pos.orderslip', compact('folio'));
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_type' => 'required|in:room_charge,walkin_pos',
            'room_id' => 'required_if:order_type,room_charge|nullable|exists:rooms,id',
            'folio_id' => 'nullable|integer|exists:folios,id',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:pos_items,id',
            'items.*.qty' => 'required|integer|min:1|max:65535',
            'payment_method' => 'nullable|in:cash,gcash',
            'cash_tendered' => 'nullable|numeric|min:0|max:1000000',
            'gcash_amount' => 'nullable|numeric|min:0|max:1000000',
            'gcash_reference' => 'nullable|string|max:50',
            'special_instructions' => 'nullable|string|max:1000',
            'shift_override_reason' => 'nullable|string|max:500',
        ]);

        $quantities = [];
        foreach ($validated['items'] as $item) {
            $id = (int) $item['id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['qty'];
        }
        try {
            $result = $this->posting->execute($request, 'pos.order', null, $validated, function () use ($validated, $quantities, $request): array {
                $folio = null;
                $room = null;

                if ($validated['order_type'] === 'room_charge') {
                    $room = Room::whereKey($validated['room_id'])->lockForUpdate()->firstOrFail();
                    if ($room->is_staff_quarters) {
                        $room->getOrCreateStaffFolio();
                    } elseif (! $room->isOccupied()) {
                        throw new DomainException('Room charges require an occupied room with an active folio.');
                    }
                    $folio = Folio::where('room_id', $room->id)->where('status', 'active')->latest('id')->lockForUpdate()->first();
                    if (! $folio) {
                        throw new DomainException('Room charges require an active folio. Refresh the room selection.');
                    }
                    if ((! $room->is_staff_quarters || ! empty($validated['folio_id'])) && (int) ($validated['folio_id'] ?? 0) !== $folio->id) {
                        throw new DomainException('The guest stay in this room has changed. Refresh the POS screen and select the guest again.');
                    }
                }
                $orderNumber = 'ORD-'.strtoupper(uniqid());
                $items = app(InventoryService::class)->consumeStockForOrder(
                    $quantities,
                    $validated['order_type'],
                    $orderNumber,
                    Auth::id(),
                    $folio ? "Charged to Room {$room->number}" : 'Walk-in POS Order'
                );
                $totalOrderAmount = (float) $items->sum(fn (PosItem $item) => $item->price * $quantities[$item->id]);
                $settlement = $this->resolveSettlement($validated, $totalOrderAmount);
                $requiresKitchen = $items->contains(fn (PosItem $item): bool => $item->kitchen_hours_only);
                $order = Order::create([
                    'user_id' => Auth::id(),
                    'folio_id' => $folio?->id,
                    'room_id' => $room?->id,
                    'transaction_id' => $orderNumber,
                    'type' => $validated['order_type'],
                    'status' => $requiresKitchen ? 'new' : 'delivered',
                    'dispatched_at' => $requiresKitchen ? null : now(),
                    ...$settlement,
                    'special_instructions' => $validated['special_instructions'] ?? null,
                    'total' => $totalOrderAmount,
                ]);

                $folioItemsPayload = $folio ? ($folio->pos_items ?? []) : [];
                foreach ($items as $posItem) {
                    $qty = $quantities[$posItem->id];
                    $subtotal = $posItem->price * $qty;

                    $orderLine = OrderItem::create([
                        'order_id' => $order->id,
                        'pos_item_id' => $posItem->id,
                        'item_name' => $posItem->name,
                        'unit_price' => $posItem->price,
                        'quantity' => $qty,
                        'subtotal' => $subtotal,
                    ]);

                    if ($folio) {
                        $folioItemsPayload[] = [
                            'name' => $posItem->name,
                            'qty' => $qty,
                            'price' => (float) $posItem->price,
                            'subtotal' => (float) $subtotal,
                            'order_id' => $order->id,
                            'order_item_id' => $orderLine->id,
                        ];
                    }
                }

                if ($folio) {
                    $folio->pos_items = $folioItemsPayload;
                    $folio->recalculate();
                    $this->audit->record($request, 'pos.room_charge', $order, ['folio_id' => $folio->id, 'total' => $order->total, 'operation_key' => $request->input('operation_key')]);
                } else {
                    $entry = $this->posting->post($request, $order, 'pos_sale', BillingService::cents($order->cash_tendered) - BillingService::cents($order->change_due), BillingService::cents($order->gcash_amount), $order->gcash_reference);
                    $order->update(['shift_id' => $entry->shift_id]);
                }

                return ['order_id' => $order->id, 'total' => $totalOrderAmount];
            });

            return redirect()->route('pos.index')
                ->with('success', 'Order placed successfully! (Total: ₱'.number_format($result['total'], 2).')');
        } catch (DomainException $e) {
            return back()->with('error', 'Order failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{payment_method: string, cash_tendered?: float, gcash_amount?: float, gcash_reference?: string, change_due?: float}
     */
    private function resolveSettlement(array $validated, float $total): array
    {
        if ($validated['order_type'] === 'room_charge') {
            return ['payment_method' => 'charge_to_room'];
        }

        $method = $validated['payment_method'] ?? null;
        $totalCents = (int) round($total * 100);
        if ($method === 'cash') {
            if (! isset($validated['cash_tendered'])) {
                throw new DomainException('Enter the actual cash tendered for this walk-in sale.');
            }
            $cashCents = (int) round((float) $validated['cash_tendered'] * 100);
            if ($cashCents < $totalCents) {
                throw new DomainException('Cash tendered must cover the order total.');
            }

            return ['payment_method' => 'cash', 'cash_tendered' => $cashCents / 100, 'change_due' => ($cashCents - $totalCents) / 100];
        }

        if ($method === 'gcash') {
            if (! isset($validated['gcash_amount']) || (int) round((float) $validated['gcash_amount'] * 100) !== $totalCents) {
                throw new DomainException('GCash amount must equal the order total.');
            }
            $reference = trim($validated['gcash_reference'] ?? '');
            if ($reference === '') {
                throw new DomainException('Enter the GCash transaction reference.');
            }

            return ['payment_method' => 'gcash', 'gcash_amount' => $totalCents / 100, 'gcash_reference' => $reference, 'change_due' => 0.0];
        }

        throw new DomainException('Choose a payment method for the walk-in sale.');
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:new,preparing,ready,delivered',
        ]);

        try {
            DB::transaction(function () use ($order, $validated) {
                $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($lockedOrder->status === $validated['status']) {
                    return;
                }
                $nextStatus = ['new' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'];
                if (($nextStatus[$lockedOrder->status] ?? null) !== $validated['status']) {
                    throw new DomainException('Order status must advance through preparing, ready, and delivered.');
                }
                $lockedOrder->status = $validated['status'];
                if ($lockedOrder->status === 'delivered') {
                    $lockedOrder->dispatched_at = now();
                }
                $lockedOrder->save();
            });
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order status updated to '.strtoupper($validated['status']).'.');
    }

    /**
     * Dedicated Kitchen Display System (KDS) for Kitchen Staff.
     */
    public function kitchenView(): View
    {
        $pendingOrders = Order::with(['room', 'folio.guest', 'items'])
            ->whereIn('status', ['new', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc')
            ->get();

        $completedToday = Order::with(['room', 'folio.guest', 'items'])
            ->where('status', 'delivered')
            ->whereDate('updated_at', today())
            ->latest('updated_at')
            ->take(10)
            ->get();

        return view('kitchen.index', compact('pendingOrders', 'completedToday'));
    }

    /**
     * Dedicated Kitchen TV Display (Optimized for 40-65" TV Monitors).
     */
    public function kitchenTvView(): View
    {
        $pendingOrders = Order::with(['room', 'folio.guest', 'items'])
            ->whereIn('status', ['new', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc')
            ->get();

        $groupedByRoom = $pendingOrders->groupBy(function ($order) {
            return $order->room ? 'Room '.$order->room->number : 'Walk-In';
        });

        $totalRoomsInQueue = $groupedByRoom->count();
        $totalItemsPending = $pendingOrders->sum(fn ($o) => $o->items->sum('quantity'));
        $newCount = $pendingOrders->where('status', 'new')->count();
        $prepCount = $pendingOrders->where('status', 'preparing')->count();
        $readyCount = $pendingOrders->where('status', 'ready')->count();

        return view('kitchen.tv', compact('pendingOrders', 'groupedByRoom', 'totalRoomsInQueue', 'totalItemsPending', 'newCount', 'prepCount', 'readyCount'));
    }
}
