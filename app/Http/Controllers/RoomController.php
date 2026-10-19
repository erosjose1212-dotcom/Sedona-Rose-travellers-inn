<?php

namespace App\Http\Controllers;

use App\Models\PosItem;
use App\Models\Room;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function __construct(private AuditService $audit, private InventoryService $inventory) {}

    /**
     * Update Room Status (e.g. available <-> maintenance).
     */
    public function updateStatus(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:available,occupied,maintenance',
            'notes' => 'nullable|string|max:500',
        ]);

        $oldStatus = DB::transaction(function () use ($room, $validated, $request): string {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            if ($lockedRoom->folios()->where('status', 'active')->exists() && ! $lockedRoom->is_staff_quarters && $validated['status'] !== 'occupied') {
                throw ValidationException::withMessages(['status' => 'Check out the active guest before changing room status.']);
            }
            if ($validated['status'] === 'occupied' && ! $lockedRoom->folios()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['status' => 'Use check-in to occupy a room.']);
            }
            $oldStatus = $lockedRoom->status;
            $lockedRoom->status = $validated['status'];
            if ($request->filled('notes')) {
                $lockedRoom->notes = $validated['notes'];
            }
            $lockedRoom->save();
            $room->setRawAttributes($lockedRoom->getAttributes(), true);

            return $oldStatus;
        });

        $message = "Room {$room->number} status changed from ".strtoupper($oldStatus).' to '.strtoupper($room->status).'.';

        return back()->with('success', $message);
    }

    /**
     * Toggle Maintenance Out-of-Order Mode.
     */
    public function toggleMaintenance(Room $room): RedirectResponse
    {
        $newStatus = DB::transaction(function () use ($room): string {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            if ($lockedRoom->status === 'occupied' || $lockedRoom->folios()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['status' => 'Cannot change maintenance status while a folio is active.']);
            }
            $newStatus = $lockedRoom->status === 'maintenance' ? 'available' : 'maintenance';
            $lockedRoom->update(['status' => $newStatus]);

            return $newStatus;
        });

        return back()->with('success', "Room {$room->number} is now marked as ".strtoupper($newStatus).'.');
    }

    /**
     * Master Pricing & Catalog Maintenance View (Admin / Owner).
     */
    public function pricingIndex(): View
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'owner']), 403, 'Unauthorized. Master Pricing Editor is restricted to Administrators and Owners only.');

        $rooms = Room::orderBy('floor')->orderByNumber()->get();
        $posItems = PosItem::where('is_retired', false)->orderBy('category')->orderBy('sort_order')->get();

        return view('admin.pricing', compact('rooms', 'posItems'));
    }

    /**
     * Update Room Tier Rate or POS Item Price (Admin / Owner).
     */
    public function updatePricing(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'owner']), 403, 'Unauthorized. Master Pricing Editor is restricted to Administrators and Owners only.');

        if ($request->has('room_id')) {
            $validated = $request->validate([
                'room_id' => 'required|exists:rooms,id',
                'base_rate_3h' => 'required|numeric|min:0|max:999999.99',
                'base_rate_4h' => 'required|numeric|min:0|max:999999.99',
                'base_rate_6h' => 'required|numeric|min:0|max:999999.99',
                'base_rate_12h' => 'required|numeric|min:0|max:999999.99',
                'base_rate_24h' => 'required|numeric|min:0|max:999999.99',
                'base_rate_promo' => 'required|numeric|min:0|max:999999.99',
            ]);

            $room = DB::transaction(function () use ($request, $validated): Room {
                $room = Room::whereKey($validated['room_id'])->lockForUpdate()->firstOrFail();
                $fields = ['base_rate_3h', 'base_rate_4h', 'base_rate_6h', 'base_rate_12h', 'base_rate_24h', 'base_rate_promo'];
                $before = $room->only($fields);
                $room->update(array_intersect_key($validated, array_flip($fields)));
                $this->audit->record($request, 'pricing.room_updated', $room, ['before' => $before, 'after' => $room->only($fields)]);

                return $room;
            });

            return back()->with('success', "Rates updated for Room {$room->number}.");
        }

        if ($request->has('pos_item_id')) {
            $validated = $request->validate([
                'pos_item_id' => 'required|exists:pos_items,id',
                'price' => 'required|numeric|min:0|max:999999.99',
                'is_available' => 'nullable|boolean',
            ]);

            $item = DB::transaction(function () use ($request, $validated): PosItem {
                $item = PosItem::whereKey($validated['pos_item_id'])->lockForUpdate()->firstOrFail();
                $before = ['price' => $item->price, 'is_available' => (bool) $item->getRawOriginal('is_available')];
                $item->update(['price' => $validated['price'], 'is_available' => $request->boolean('is_available')]);
                $this->audit->record($request, 'catalog.item_pricing_updated', $item, ['before' => $before, 'after' => ['price' => $item->price, 'is_available' => (bool) $item->getRawOriginal('is_available')]]);

                return $item;
            });

            return back()->with('success', "Price updated for {$item->name}.");
        }

        return back()->with('error', 'No price update data received.');
    }

    /**
     * Store a new POS Catalog Item (Admin / Owner).
     */
    public function storeItem(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'owner']), 403, 'Unauthorized.');

        $validated = $request->validate([
            'name' => 'required|string|max:120|unique:pos_items,name',
            'category' => 'required|string|max:60',
            'price' => 'required|numeric|min:0|max:999999.99',
            'is_tracked' => 'nullable|boolean',
            'stock_quantity' => 'nullable|integer|min:0|max:100000',
            'reorder_level' => 'nullable|integer|min:0',
            'kitchen_hours_only' => 'nullable|boolean',
            'is_available' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $maxSort = PosItem::where('category', $validated['category'])->max('sort_order') ?? 0;
            $item = PosItem::create([
                'name' => trim($validated['name']), 'category' => trim($validated['category']), 'price' => $validated['price'],
                'is_tracked' => $request->boolean('is_tracked'), 'stock_quantity' => 0,
                'reorder_level' => (int) ($validated['reorder_level'] ?? 5), 'kitchen_hours_only' => $request->boolean('kitchen_hours_only'),
                'is_available' => $request->boolean('is_available'), 'sort_order' => $maxSort + 1,
            ]);
            if ($item->is_tracked) {
                $item = $this->inventory->setStockCount($item->id, (int) ($validated['stock_quantity'] ?? 0), 'opening_balance', 'Opening stock recorded at catalog creation.', $request->user()->id);
                $item->update(['is_available' => $request->boolean('is_available')]);
            }
            $item->refresh();
            $metadata = $item->only(['name', 'category', 'price', 'is_tracked', 'stock_quantity', 'reorder_level', 'kitchen_hours_only', 'is_available', 'sort_order', 'is_retired']);
            $metadata['is_available'] = (bool) $item->getRawOriginal('is_available');
            $this->audit->record($request, 'catalog.item_created', $item, ['before' => null, 'after' => $metadata]);
        });

        return back()->with('success', "New catalog item \u201c{$validated['name']}\u201d added successfully.");
    }

    /**
     * Delete a POS Catalog Item (Admin / Owner).
     */
    public function destroyItem(Request $request, PosItem $posItem): RedirectResponse
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'owner']), 403, 'Unauthorized.');

        $name = $posItem->name;
        DB::transaction(function () use ($request, $posItem): void {
            $item = PosItem::whereKey($posItem->id)->lockForUpdate()->firstOrFail();
            $before = ['is_retired' => (bool) $item->is_retired, 'is_available' => (bool) $item->getRawOriginal('is_available')];
            $item->update(['is_retired' => true, 'is_available' => false]);
            $this->audit->record($request, 'catalog.item_retired', $item, ['before' => $before, 'after' => ['is_retired' => true, 'is_available' => false]]);
        });

        return back()->with('success', "Catalog item \u201c{$name}\u201d has been retired. Sales and stock history have been retained.");
    }
}
