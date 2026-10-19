<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\InventoryEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosItem;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Include one complimentary kit per room check-in without blocking a stay when stock needs updating.
     */
    public function issueGuestHygieneKit(Folio $folio): ?string
    {
        return DB::transaction(function () use ($folio): ?string {
            $kit = PosItem::notRetired()->where('name', 'Guest Amenity Hygiene Kit')->orderBy('id')->lockForUpdate()->first();
            if (! $kit) {
                return 'The free Guest Amenity Hygiene Kit is included. Its inventory item is missing or retired; update the catalog and stock in Inventory.';
            }

            $kit->price = 0;
            $kit->is_tracked = true;
            $user = $folio->user_id ? User::find($folio->user_id) : Auth::user();
            if ($kit->stock_quantity > 0) {
                $this->decrementLockedItem($kit, 1, 'guest_amenity', $folio->transaction_id, $user,
                    'One complimentary Guest Amenity Hygiene Kit included at room check-in.');

                return null;
            }

            $this->decrementLockedItem($kit, 0, 'amenity_shortage', $folio->transaction_id, $user,
                'One complimentary kit included at check-in, but no stock was recorded. Update the physical inventory count.');

            return 'The free Guest Amenity Hygiene Kit is included, but its recorded stock is zero. Update its stock quantity in Inventory.';
        });
    }

    /**
     * @param  array<int, int>  $quantities  Aggregated quantities keyed by catalog item ID.
     * @return Collection<int, PosItem> Locked catalog items with authoritative prices.
     */
    public function consumeStockForOrder(
        array $quantities,
        string $reason = 'sold',
        ?string $referenceId = null,
        ?int $userId = null,
        ?string $notes = null
    ): Collection {
        if ($quantities === []) {
            throw new DomainException('Orders must contain at least one item.');
        }
        foreach ($quantities as $id => $qty) {
            if ($id < 1 || ! is_int($qty) || $qty < 1 || $qty > 65535) {
                throw new DomainException('Order quantities must be between 1 and 65535 units per item.');
            }
        }

        return DB::transaction(function () use ($quantities, $reason, $referenceId, $userId, $notes) {
            $catalog = PosItem::whereIn('id', array_keys($quantities))->get();
            $usesEgg = $catalog->contains(fn (PosItem $item) => $item->requiresEgg());
            $eggId = $usesEgg ? PosItem::getMasterEggItem()?->id : null;
            $ids = array_keys($quantities);
            if ($eggId !== null) {
                $ids[] = $eggId;
            }
            $lockedItems = $this->lockItems($ids);
            $items = $lockedItems->only(array_keys($quantities));

            if ($items->count() !== count($quantities)) {
                throw new DomainException('One or more ordered items no longer exist.');
            }

            $demand = $quantities;
            $recipeUsage = [];
            foreach ($items as $item) {
                if ($item->is_retired || ! (bool) $item->getRawOriginal('is_available')) {
                    throw new DomainException("{$item->name} is not available for ordering.");
                }
                if ($item->requiresEgg() && $eggId !== null) {
                    $eggQty = $item->getEggRequirementQty() * $quantities[$item->id];
                    $demand[$eggId] = ($demand[$eggId] ?? 0) + $eggQty;
                    $recipeUsage[] = [$item, $eggQty];
                }
            }

            foreach ($demand as $id => $qty) {
                $item = $lockedItems->get($id);
                if ($item->is_retired || ! (bool) $item->getRawOriginal('is_available')) {
                    throw new DomainException("{$item->name} is not available for ordering.");
                }
                if ($item->is_tracked && $item->stock_quantity < $qty) {
                    $label = $id === $eggId ? 'egg stock in kitchen pantry' : "stock for {$item->name}";
                    throw new DomainException("Insufficient {$label} (Only {$item->stock_quantity} available, ordered {$qty}).");
                }
            }

            $user = $userId ? User::find($userId) : Auth::user();
            foreach ($items as $item) {
                if ($item->is_tracked) {
                    $this->decrementLockedItem($item, $quantities[$item->id], $reason, $referenceId, $user, $notes);
                }
            }
            foreach ($recipeUsage as [$dish, $eggQty]) {
                $egg = $lockedItems->get($eggId);
                if ($egg->is_tracked) {
                    $recipeNotes = "Recipe ingredient: {$eggQty}x egg used for order of {$dish->name}".($notes ? " ({$notes})" : '');
                    $this->decrementLockedItem($egg, $eggQty, 'recipe_ingredient', $referenceId, $user, $recipeNotes);
                }
            }

            PosItem::clearEggCache();

            return $items;
        });
    }

    public function atomicDecrementStock(
        int $posItemId,
        int $qty,
        string $reason = 'sold',
        ?string $referenceId = null,
        ?int $userId = null,
        ?string $notes = null
    ): bool {
        $this->consumeStockForOrder([$posItemId => $qty], $reason, $referenceId, $userId, $notes);

        return true;
    }

    /**
     * Acquire each row in ascending ID order, independently of cart ordering.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, PosItem>
     */
    private function lockItems(array $ids): Collection
    {
        $ids = array_unique($ids);
        sort($ids, SORT_NUMERIC);
        $items = new Collection;
        foreach ($ids as $id) {
            $item = PosItem::whereKey($id)->lockForUpdate()->first();
            if ($item) {
                $items->put($item->id, $item);
            }
        }

        return $items;
    }

    private function decrementLockedItem(
        PosItem $item,
        int $qty,
        string $reason,
        ?string $referenceId,
        ?User $user,
        ?string $notes
    ): void {
        $item->stock_quantity -= $qty;
        $item->is_available = $item->stock_quantity > 0;
        $item->save();

        InventoryEvent::create([
            'pos_item_id' => $item->id,
            'item_name' => $item->name,
            'event_type' => $reason,
            'quantity_change' => -$qty,
            'balance_after' => $item->stock_quantity,
            'reference_id' => $referenceId,
            'user_id' => $user?->id,
            'operator_name' => $user?->name ?? 'System Cashier',
            'notes' => $notes ?? "Stock decreased by {$qty} via {$reason}",
        ]);
    }

    public function deductRelationalEggStock(
        PosItem $dishItem,
        int $eggQty,
        ?string $referenceId = null,
        ?int $userId = null,
        ?string $notes = null
    ): ?PosItem {
        if ($eggQty < 1) {
            throw new DomainException('Ingredient quantity must be positive.');
        }

        return DB::transaction(function () use ($dishItem, $eggQty, $referenceId, $userId, $notes) {
            $eggId = PosItem::getMasterEggItem()?->id;
            if ($eggId === null || $eggId === $dishItem->id) {
                return null;
            }
            $items = $this->lockItems([$dishItem->id, $eggId]);
            $egg = $items->get($eggId);
            if (! $egg || ! $egg->is_tracked) {
                return null;
            }
            if (! $egg->is_available || $egg->stock_quantity < $eggQty) {
                throw new DomainException('Insufficient egg stock in kitchen pantry.');
            }
            $user = $userId ? User::find($userId) : Auth::user();
            $this->decrementLockedItem($egg, $eggQty, 'recipe_ingredient', $referenceId, $user, $notes ?? "Recipe ingredient: {$eggQty}x egg used for order of {$dishItem->name}");
            PosItem::clearEggCache();

            return $egg;
        });
    }

    public function returnUnpreparedOrderStock(Order $order, OrderItem $line, int $quantity, User $actor, string $reason): void
    {
        $movements = InventoryEvent::where('reference_id', $order->transaction_id)->where('quantity_change', '<', 0)->get();
        $returned = [];
        foreach ($movements as $movement) {
            if ((int) $movement->pos_item_id === (int) $line->pos_item_id && $movement->event_type !== 'recipe_ingredient') {
                $returned[$movement->pos_item_id] = $quantity;
            }
            if ($movement->event_type === 'recipe_ingredient'
                && preg_match('/^Recipe ingredient: [0-9]+x egg used for order of '.preg_quote($line->item_name, '/').'(?: \(|$)/', $movement->notes ?? '')) {
                $returned[$movement->pos_item_id] = $quantity;
            }
        }
        foreach ($this->lockItems(array_keys($returned)) as $item) {
            $previous = (int) $item->stock_quantity;
            $item->stock_quantity += $returned[$item->id];
            if ($item->stock_quantity > 100000) {
                throw ValidationException::withMessages(['quantity' => 'Returned stock exceeds the inventory limit.']);
            }
            $item->is_available = (bool) $item->getRawOriginal('is_available') || $previous === 0;
            $item->save();
            InventoryEvent::create([
                'pos_item_id' => $item->id, 'item_name' => $item->name, 'event_type' => 'order_correction',
                'quantity_change' => $returned[$item->id], 'balance_after' => $item->stock_quantity,
                'reference_id' => $order->transaction_id, 'user_id' => $actor->id,
                'operator_name' => $actor->name, 'notes' => $reason,
            ]);
        }
        PosItem::clearEggCache();
    }

    public function addStock(int $posItemId, int $quantity, ?string $notes, int $userId): PosItem
    {
        if ($quantity < 1 || $quantity > 100000) {
            throw ValidationException::withMessages(['stock_quantity' => 'Enter a whole number of units to add between 1 and 100000.']);
        }

        return DB::transaction(function () use ($posItemId, $quantity, $notes, $userId): PosItem {
            $item = PosItem::whereKey($posItemId)->lockForUpdate()->firstOrFail();
            if ($item->is_retired) {
                throw ValidationException::withMessages(['stock_quantity' => 'Retired catalog items cannot be restocked.']);
            }
            $newCount = (int) $item->stock_quantity + $quantity;
            if ($newCount > 100000) {
                throw ValidationException::withMessages(['stock_quantity' => 'Adding this delivery would exceed the stock limit of 100000.']);
            }
            $this->setLockedCount($item, $newCount, 'restock', $notes ?? "Received {$quantity} additional units.", $userId);

            return $item;
        });
    }

    public function setStockCount(
        int $posItemId,
        int $newCount,
        string $reason = 'adjustment',
        ?string $notes = null,
        ?int $userId = null
    ): PosItem {
        if ($newCount < 0 || $newCount > 100000) {
            throw ValidationException::withMessages(['stock_quantity' => 'Stock quantity must be between 0 and 100000.']);
        }

        return DB::transaction(function () use ($posItemId, $newCount, $reason, $notes, $userId) {
            $item = PosItem::whereKey($posItemId)->lockForUpdate()->firstOrFail();
            if ($item->is_retired) {
                throw ValidationException::withMessages(['stock_quantity' => 'Retired catalog items cannot be restocked.']);
            }
            $this->setLockedCount($item, $newCount, $reason, $notes, $userId);

            return $item;
        });
    }

    private function setLockedCount(PosItem $item, int $newCount, string $reason, ?string $notes, ?int $userId): void
    {
        $previousStock = (int) $item->stock_quantity;
        $user = $userId ? User::find($userId) : Auth::user();
        $item->stock_quantity = $newCount;
        $item->is_available = $newCount > 0;
        $item->save();
        InventoryEvent::create([
            'pos_item_id' => $item->id,
            'item_name' => $item->name,
            'event_type' => $reason,
            'quantity_change' => $newCount - $previousStock,
            'balance_after' => $newCount,
            'user_id' => $user?->id,
            'operator_name' => $user?->name ?? 'Duty Operator',
            'notes' => $notes ?? "Stock set from {$previousStock} to {$newCount} ({$reason})",
        ]);
        PosItem::clearEggCache();
    }

    /** @param array<int|string, int|string|null> $counts */
    public function batchRecount(array $counts, ?int $userId = null, ?string $notes = null): int
    {
        $normalized = [];
        foreach ($counts as $itemId => $count) {
            $id = filter_var($itemId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw ValidationException::withMessages(['counts' => 'Every count must reference an existing catalog item.']);
            }
            if ($count === null || $count === '') {
                $normalized[$id] = null;

                continue;
            }
            $value = filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
            if ($value === false) {
                throw ValidationException::withMessages(['counts' => 'Counts must be whole numbers between 0 and 100000.']);
            }
            $normalized[$id] = $value;
        }

        return DB::transaction(function () use ($normalized, $userId, $notes) {
            $items = $this->lockItems(array_keys($normalized));
            if ($items->count() !== count($normalized) || $items->contains(fn (PosItem $item) => $item->is_retired)) {
                throw ValidationException::withMessages(['counts' => 'Every count must reference an active catalog item.']);
            }
            $updated = 0;
            foreach ($items as $item) {
                if ($normalized[$item->id] !== null) {
                    $this->setLockedCount($item, $normalized[$item->id], 'recount', $notes, $userId);
                    $updated++;
                }
            }

            return $updated;
        });
    }

    public function generateMovementCsv(): string
    {
        $events = InventoryEvent::with('posItem')->latest()->get();
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['ID', 'Date Time', 'Item Name', 'Category', 'Event Type', 'Quantity Change', 'Balance After', 'Reference ID', 'Operator', 'Notes']);
        foreach ($events as $event) {
            fputcsv($output, [
                $event->id,
                $event->created_at->format('Y-m-d H:i:s'),
                $this->csvText($event->item_name),
                $this->csvText($event->posItem->category ?? 'N/A'),
                strtoupper($event->event_type),
                $event->quantity_change > 0 ? "+{$event->quantity_change}" : $event->quantity_change,
                $event->balance_after,
                $this->csvText($event->reference_id ?? '—'),
                $this->csvText($event->operator_name ?? 'Staff'),
                $this->csvText($event->notes ?? ''),
            ]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function csvText(string $value): string
    {
        return preg_match('/^\s*[=+\-@]|^[\t\r\n]/u', $value) ? "'".$value : $value;
    }

    /** @deprecated Use deductRelationalEggStock() directly. */
    public function syncBreakfastItemsStockWithEgg(?PosItem $eggItem = null): void
    {
        PosItem::clearEggCache();
    }
}
