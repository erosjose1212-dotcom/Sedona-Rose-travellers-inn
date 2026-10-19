<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\InventoryEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosInventoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
    }

    #[TestWith([false, 'delivered', 0])]
    #[TestWith([true, 'new', 1])]
    public function test_pos_preparation_queue_matches_whether_items_require_the_kitchen(bool $kitchenItem, string $status, int $pending): void
    {
        $cashier = $this->cashier();
        $cashier->givePermissionTo(Permission::findOrCreate('view_kds', 'web'));
        $item = $this->item(['kitchen_hours_only' => $kitchenItem]);

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 60,
            'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertRedirect()->assertSessionHas('success');

        $order = Order::sole();
        $this->assertSame($status, $order->status);
        $this->assertSame($status === 'delivered', $order->dispatched_at !== null);
        $this->assertSame(9, $item->fresh()->stock_quantity);
        $this->withoutVite();
        $this->get(route('kitchen.view'))->assertOk()->assertViewHas('pendingOrders', fn ($orders): bool => $orders->count() === $pending);
    }

    public function test_duplicate_lines_are_aggregated_and_use_authoritative_prices(): void
    {
        $cashier = $this->cashier();
        $item = $this->item(['stock_quantity' => 5]);

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000,
            'items' => [['id' => $item->id, 'qty' => 2, 'price' => 1], ['id' => $item->id, 'qty' => 3, 'price' => 1]],
            'special_instructions' => 'Deliver with napkins.',
        ])->assertRedirect(route('pos.index'))->assertSessionHas('success');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('orders', ['total' => 300, 'special_instructions' => 'Deliver with napkins.']);
        $this->assertDatabaseHas('order_items', ['pos_item_id' => $item->id, 'quantity' => 5, 'unit_price' => 60, 'subtotal' => 300]);
        $this->assertSame(0, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'quantity_change' => -5, 'balance_after' => 0]);
    }

    public function test_duplicate_lines_cannot_sell_more_than_total_stock(): void
    {
        $item = $this->item(['stock_quantity' => 5]);

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000,
            'items' => [['id' => $item->id, 'qty' => 4], ['id' => $item->id, 'qty' => 4]],
        ])->assertSessionHas('error', 'Order failed: Insufficient stock for Bottled Soda (Only 5 available, ordered 8).');

        $this->assertSame(5, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_cash_walk_in_sale_records_actual_tender_and_change(): void
    {
        $item = $this->item();

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 200,
            'items' => [['id' => $item->id, 'qty' => 2]],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('orders', ['total' => 120, 'payment_method' => 'cash', 'cash_tendered' => 200, 'change_due' => 80, 'gcash_amount' => 0]);
        $this->assertSame(8, $item->fresh()->stock_quantity);
    }

    public function test_gcash_walk_in_sale_records_exact_payment_and_trimmed_reference(): void
    {
        $item = $this->item();

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'gcash', 'gcash_amount' => 120,
            'gcash_reference' => '  GCASH-123456  ',
            'items' => [['id' => $item->id, 'qty' => 2]],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('orders', ['total' => 120, 'payment_method' => 'gcash', 'gcash_amount' => 120, 'gcash_reference' => 'GCASH-123456', 'change_due' => 0, 'cash_tendered' => 0]);
        $this->assertSame(8, $item->fresh()->stock_quantity);
    }

    public function test_walk_in_sale_attaches_payment_to_the_locked_active_shift(): void
    {
        $cashier = $this->cashier();
        $item = $this->item();
        $shift = Shift::firstOrFail();

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 100,
            'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('orders', ['shift_id' => $shift->id, 'cash_tendered' => 100, 'change_due' => 40]);
    }

    public function test_frozen_shift_rejects_walk_in_payment_and_rolls_back_stock(): void
    {
        $cashier = $this->cashier();
        $item = $this->item();
        Shift::firstOrFail()->update(['is_frozen' => true]);

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 100,
            'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHasErrors('shift');

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    #[TestWith([[]])]
    #[TestWith([['payment_method' => 'cash']])]
    #[TestWith([['payment_method' => 'cash', 'cash_tendered' => 59]])]
    #[TestWith([['payment_method' => 'gcash', 'gcash_reference' => 'GCASH-123456']])]
    #[TestWith([['payment_method' => 'gcash', 'gcash_amount' => 59, 'gcash_reference' => 'GCASH-123456']])]
    #[TestWith([['payment_method' => 'gcash', 'gcash_amount' => 61, 'gcash_reference' => 'GCASH-123456']])]
    #[TestWith([['payment_method' => 'gcash', 'gcash_amount' => 60]])]
    #[TestWith([['payment_method' => 'gcash', 'gcash_amount' => 60, 'gcash_reference' => '   ']])]
    public function test_walk_in_sale_requires_a_complete_sufficient_payment_and_rolls_back_stock(array $payment): void
    {
        $item = $this->item();

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()] + array_merge([
            'order_type' => 'walkin_pos', 'items' => [['id' => $item->id, 'qty' => 1]],
        ], $payment))->assertSessionHas('error');

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_direct_egg_sales_and_recipe_demand_share_one_stock_budget(): void
    {
        $egg = $this->egg(5);
        $dish = $this->item(['name' => 'Tapsilog', 'category' => 'Breakfast', 'stock_quantity' => 10]);

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000,
            'items' => [['id' => $egg->id, 'qty' => 2], ['id' => $dish->id, 'qty' => 4]],
        ])->assertSessionHas('error', 'Order failed: Insufficient egg stock in kitchen pantry (Only 5 available, ordered 6).');

        $this->assertSame(5, $egg->fresh()->stock_quantity);
        $this->assertSame(10, $dish->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_untracked_dish_still_consumes_tracked_recipe_ingredients(): void
    {
        $egg = $this->egg(5);
        $dish = $this->item(['name' => 'Tapsilog', 'category' => 'Breakfast', 'is_tracked' => false, 'stock_quantity' => 0]);

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000, 'items' => [['id' => $dish->id, 'qty' => 2]],
        ])->assertSessionHas('success');

        $this->assertSame(0, $dish->fresh()->stock_quantity);
        $this->assertSame(3, $egg->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $egg->id, 'event_type' => 'recipe_ingredient', 'quantity_change' => -2, 'balance_after' => 3]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_retired_master_egg_blocks_dependent_recipes_without_any_mutations(bool $tracked): void
    {
        $egg = $this->egg(5);
        $dish = $this->item(['name' => 'Tapsilog', 'category' => 'Breakfast']);
        $this->assertTrue($dish->isOrderable());
        $egg->update(['is_retired' => true, 'is_tracked' => $tracked]);

        $this->assertSame($egg->id, PosItem::getMasterEggItem()?->id);
        $this->assertFalse($dish->fresh()->isOrderable());
        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 100,
            'items' => [['id' => $dish->id, 'qty' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame(5, $egg->fresh()->stock_quantity);
        $this->assertSame(10, $dish->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_recipe_without_a_configured_master_egg_preserves_existing_order_behavior(): void
    {
        $dish = $this->item(['name' => 'Tapsilog', 'category' => 'Breakfast']);
        $this->assertNull(PosItem::getMasterEggItem());
        $this->assertTrue($dish->isOrderable());

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 100,
            'items' => [['id' => $dish->id, 'qty' => 1]],
        ])->assertSessionHas('success');

        $this->assertSame(9, $dish->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_events', 1);
    }

    #[TestWith(['unavailable'])]
    #[TestWith(['retired'])]
    public function test_order_rejects_non_orderable_items_without_mutating_stock(string $condition): void
    {
        $item = $this->item([
            'is_available' => $condition !== 'unavailable',
            'is_retired' => $condition === 'retired',
            'kitchen_hours_only' => true,
        ]);
        $this->travelTo(now()->setTime(23, 0));

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000, 'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    #[TestWith([1, 'Breakfast', 'Tapsilog'])]
    #[TestWith([23, 'Favorites', 'Lechon Kawali'])]
    public function test_stocked_kitchen_dishes_can_be_ordered_at_any_hour(int $hour, string $category, string $name): void
    {
        $this->travelTo(now()->setTime($hour, 31));
        $egg = $this->egg(50);
        $dish = $this->item(['name' => $name, 'category' => $category, 'stock_quantity' => 50, 'kitchen_hours_only' => true]);
        $cashier = $this->cashier();

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000,
            'items' => [['id' => $dish->id, 'qty' => 1]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(49, $dish->fresh()->stock_quantity);
        $this->assertSame($category === 'Breakfast' ? 49 : 50, $egg->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_an_exhausted_stock_service_call_cannot_create_phantom_movements(): void
    {
        $item = $this->item(['stock_quantity' => 1]);
        $service = app(InventoryService::class);
        $service->atomicDecrementStock($item->id, 1);

        try {
            $service->atomicDecrementStock($item->id, 1);
            $this->fail('Exhausted stock must reject a subsequent deduction.');
        } catch (DomainException) {
            $this->assertSame(0, $item->fresh()->stock_quantity);
            $this->assertDatabaseCount('inventory_events', 1);
        }
    }

    public function test_room_charge_requires_an_active_folio_even_when_room_exists(): void
    {
        $room = $this->room();
        $item = $this->item();

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'room_charge', 'room_id' => $room->id,
            'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_room_charge_preserves_existing_folio_items_when_appending(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier, ['pos_items' => [['name' => 'Water', 'qty' => 1, 'price' => 20, 'subtotal' => 20]]]);
        $item = $this->item();

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'room_charge', 'room_id' => $folio->room_id, 'folio_id' => $folio->id,
            'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('success');

        $folio->refresh();
        $this->assertCount(2, $folio->pos_items);
        $this->assertSame('Water', $folio->pos_items[0]['name']);
        $this->assertSame('Bottled Soda', $folio->pos_items[1]['name']);
        $this->assertSame(80.0, (float) $folio->pos_total);
        $this->assertDatabaseHas('orders', ['folio_id' => $folio->id, 'room_id' => $folio->room_id, 'total' => 60]);
    }

    public function test_a_stale_pos_room_selection_cannot_charge_the_next_guest(): void
    {
        $cashier = $this->cashier();
        $old = $this->folio($cashier);
        $item = $this->item();
        $old->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $next = $old->replicate();
        $next->fill(['transaction_id' => Folio::generateTransactionId(), 'status' => 'active', 'checked_out_at' => null,
            'guest_id' => Guest::create(['name' => 'Next guest'])->id])->save();

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'room_charge', 'room_id' => $old->room_id, 'folio_id' => $old->id,
            'items' => [['id' => $item->id, 'qty' => 1]]])->assertSessionHas('error');

        $this->assertSame([], $next->fresh()->pos_items);
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_guest_room_orders_require_the_selected_stay_reference(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $item = $this->item();

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'room_charge', 'room_id' => $folio->room_id,
            'items' => [['id' => $item->id, 'qty' => 1]]])->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $item->fresh()->stock_quantity);
    }

    public function test_staff_room_creates_its_special_folio_for_room_orders(): void
    {
        $room = $this->room(['status' => 'available', 'is_staff_quarters' => true]);
        $item = $this->item();

        $this->actingAs($this->cashier())->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'room_charge', 'room_id' => $room->id,
            'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('success');

        $folio = Folio::where('room_id', $room->id)->sole();
        $this->assertSame('active', $folio->status);
        $this->assertSame(0.0, (float) $folio->room_charge);
        $this->assertSame(60.0, (float) $folio->pos_total);
        $this->assertDatabaseHas('orders', ['folio_id' => $folio->id, 'room_id' => $room->id]);
    }

    public function test_quick_addon_rejects_a_checked_out_folio(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier, ['status' => 'checked_out']);
        $item = $this->item();

        $this->actingAs($cashier)->post(route('folios.addon', $folio), ['operation_key' => (string) Str::uuid(),
            'pos_item_id' => $item->id, 'quantity' => 1,
        ])->assertSessionHas('error');

        $this->assertSame([], $folio->fresh()->pos_items);
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_quick_addon_rejects_unavailable_items_without_charging_the_folio(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $item = $this->item(['is_available' => false]);

        $this->actingAs($cashier)->post(route('folios.addon', $folio), ['operation_key' => (string) Str::uuid(),
            'pos_item_id' => $item->id, 'quantity' => 1,
        ])->assertSessionHas('error');

        $this->assertSame([], $folio->fresh()->pos_items);
        $this->assertSame(0.0, (float) $folio->fresh()->pos_total);
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quick_addon_rolls_back_stock_order_and_bill_when_a_later_write_fails(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $item = $this->item();
        OrderItem::creating(function () {
            throw new RuntimeException('Simulated order line write failure.');
        });

        try {
            $this->actingAs($cashier)->post(route('folios.addon', $folio), ['operation_key' => (string) Str::uuid(),
                'pos_item_id' => $item->id, 'quantity' => 1,
            ])->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.OrderItem::class);
        }

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertSame([], $folio->fresh()->pos_items);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_stock_update_accepts_omitted_optional_notes(): void
    {
        $item = $this->item();

        $this->actingAs($this->cashier())->post(route('inventory.update-stock', $item), ['operation_key' => (string) Str::uuid(),
            'stock_quantity' => 15, 'reason' => 'restock',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(25, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'event_type' => 'restock', 'quantity_change' => 15]);
    }

    #[TestWith(['missing_id'])]
    #[TestWith(['invalid_key'])]
    #[TestWith(['negative_count'])]
    public function test_recount_rejects_invalid_entries_without_partial_updates(string $condition): void
    {
        $first = $this->item();
        $second = $this->item(['name' => 'Water']);
        $counts = [$first->id => 3];
        if ($condition === 'missing_id') {
            $counts[$second->id + 100] = 4;
        } elseif ($condition === 'invalid_key') {
            $counts['invalid'] = 4;
        } else {
            $counts[$second->id] = -1;
        }

        $admin = $this->cashier();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin)->post(route('inventory.recount'), ['operation_key' => (string) Str::uuid(), 'counts' => $counts])->assertSessionHasErrors();

        $this->assertSame(10, $first->fresh()->stock_quantity);
        $this->assertSame(10, $second->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_recount_rolls_back_all_counts_when_a_later_ledger_write_fails(): void
    {
        $first = $this->item();
        $second = $this->item(['name' => 'Water']);
        InventoryEvent::creating(function (InventoryEvent $event) use ($second) {
            if ($event->pos_item_id === $second->id) {
                throw new RuntimeException('Simulated recount ledger write failure.');
            }
        });

        try {
            $admin = $this->cashier();
            $admin->assignRole(Role::findOrCreate('admin', 'web'));
            $this->actingAs($admin)->post(route('inventory.recount'), ['operation_key' => (string) Str::uuid(),
                'counts' => [$first->id => 3, $second->id => 4],
            ])->assertServerError();
        } finally {
            Event::forget('eloquent.creating: '.InventoryEvent::class);
        }

        $this->assertSame(10, $first->fresh()->stock_quantity);
        $this->assertSame(10, $second->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_recount_updates_every_valid_count_and_records_balances(): void
    {
        $first = $this->item();
        $second = $this->item(['name' => 'Water']);

        $admin = $this->cashier();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin)->post(route('inventory.recount'), ['operation_key' => (string) Str::uuid(),
            'counts' => [$second->id => 4, $first->id => 3],
        ])->assertSessionHas('success');

        $this->assertSame(3, $first->fresh()->stock_quantity);
        $this->assertSame(4, $second->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 2);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $first->id, 'quantity_change' => -7, 'balance_after' => 3]);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $second->id, 'quantity_change' => -6, 'balance_after' => 4]);
    }

    public function test_kitchen_status_advances_in_order_and_retries_preserve_dispatch_time(): void
    {
        $cashier = $this->cashier();
        $order = $this->order($cashier);
        foreach (['preparing', 'ready', 'delivered'] as $status) {
            $this->actingAs($cashier)->post(route('pos.order.status', $order), ['status' => $status])->assertSessionHas('success');
            $this->assertSame($status, $order->fresh()->status);
        }
        $dispatchTime = $order->fresh()->dispatched_at->toIso8601String();
        $this->travel(5)->minutes();

        $this->actingAs($cashier)->post(route('pos.order.status', $order), ['status' => 'delivered'])->assertSessionHas('success');

        $this->assertSame($dispatchTime, $order->fresh()->dispatched_at->toIso8601String());
    }

    #[TestWith(['new', 'ready'])]
    #[TestWith(['new', 'delivered'])]
    #[TestWith(['preparing', 'new'])]
    #[TestWith(['ready', 'preparing'])]
    #[TestWith(['delivered', 'ready'])]
    public function test_kitchen_status_rejects_skipped_or_backward_transitions(string $current, string $requested): void
    {
        $cashier = $this->cashier();
        $order = $this->order($cashier, $current);

        $this->actingAs($cashier)->post(route('pos.order.status', $order), ['status' => $requested])->assertSessionHas('error');

        $this->assertSame($current, $order->fresh()->status);
        $this->assertNull($order->fresh()->dispatched_at);
    }

    public function test_retiring_catalog_items_preserves_order_lines_and_inventory_history(): void
    {
        $owner = $this->cashier('owner');
        $item = $this->item();
        $this->actingAs($owner)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000, 'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('success');

        $this->delete(route('admin.pricing.items.destroy', $item))->assertRedirect();

        $this->assertTrue($item->fresh()->is_retired);
        $this->assertFalse($item->fresh()->isOrderable());
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->get(route('inventory.export-csv'))->assertSee('Bottled Soda');
        $this->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 10000, 'items' => [['id' => $item->id, 'qty' => 1]],
        ])->assertSessionHas('error');
        $this->assertSame(9, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    private function cashier(string $role = 'cashier'): User
    {
        $staffRole = Role::findOrCreate($role, 'web');
        foreach (['manage_pos', 'manage_folios', 'post_pos_sales', 'record_payments', 'update_kitchen_status', 'manage_shifts'] as $permission) {
            $staffRole->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user = User::factory()->create();
        $user->assignRole($role);
        Shift::create(['opened_by' => $user->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()]);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function item(array $attributes = []): PosItem
    {
        return PosItem::create(array_merge([
            'name' => 'Bottled Soda', 'category' => 'Drinks', 'price' => 60,
            'stock_quantity' => 10, 'is_tracked' => true, 'is_available' => true,
            'kitchen_hours_only' => false, 'is_retired' => false,
        ], $attributes));
    }

    private function egg(int $quantity): PosItem
    {
        return $this->item(['name' => 'Egg (Fried/Boiled)', 'category' => 'Kitchen Extras', 'price' => 20, 'stock_quantity' => $quantity]);
    }

    /** @param array<string, mixed> $attributes */
    private function room(array $attributes = []): Room
    {
        return Room::create(array_merge([
            'number' => '101', 'floor' => 1, 'name' => 'Classic 101',
            'type' => 'Classic Room', 'status' => 'occupied',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function folio(User $cashier, array $attributes = []): Folio
    {
        return Folio::create(array_merge([
            'room_id' => $this->room()->id, 'guest_id' => Guest::create(['name' => 'Test Guest'])->id,
            'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(),
            'rate_tier' => '3h', 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3),
            'status' => 'active', 'pos_items' => [], 'room_charge' => 395,
        ], $attributes));
    }

    private function order(User $cashier, string $status = 'new'): Order
    {
        return Order::create(['user_id' => $cashier->id, 'transaction_id' => 'ORD-TEST', 'type' => 'walkin_pos', 'status' => $status, 'total' => 60]);
    }
}
