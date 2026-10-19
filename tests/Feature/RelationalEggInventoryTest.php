<?php

namespace Tests\Feature;

use App\Models\PosItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RelationalEggInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected PosItem $egg;

    protected PosItem $chicksilog;

    protected PosItem $bangsilog;

    protected PosItem $fries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'pau@sedonapms.com')->first();
        Shift::create(['opened_by' => $this->cashier->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()]);

        $this->egg = PosItem::where('name', 'Egg (Fried/Boiled)')->first();
        $this->chicksilog = PosItem::where('name', 'Chicksilog')->first();
        $this->bangsilog = PosItem::where('name', 'Bangsilog')->first();
        $this->fries = PosItem::where('name', 'French Fries')->first();

        $inventoryService = app(InventoryService::class);
        $inventoryService->setStockCount($this->egg->id, 50);
        $inventoryService->setStockCount($this->chicksilog->id, 20);
        $inventoryService->setStockCount($this->bangsilog->id, 15);
        $inventoryService->setStockCount($this->fries->id, 30);
    }

    public function test_chicksilog_order_deducts_chicksilog_and_deducts_egg_while_other_dishes_remain_untouched(): void
    {
        $inventoryService = app(InventoryService::class);

        // Pre-condition: Chicksilog=20, Bangsilog=15, Egg=50
        $this->assertEquals(20, $this->chicksilog->fresh()->stock_quantity);
        $this->assertEquals(15, $this->bangsilog->fresh()->stock_quantity);
        $this->assertEquals(50, $this->egg->fresh()->stock_quantity);

        // Ordering 1x Chicksilog via POS
        $response = $this->actingAs($this->cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos',
            'payment_method' => 'cash',
            'cash_tendered' => 10000,
            'items' => [
                ['id' => $this->chicksilog->id, 'qty' => 1],
            ],
        ]);

        $response->assertRedirect(route('pos.index'));
        $response->assertSessionHas('success');

        // Post-condition:
        // 1. Chicksilog decremented by 1 (20 -> 19)
        $this->assertEquals(19, $this->chicksilog->fresh()->stock_quantity);
        // 2. Egg decremented by 1 (50 -> 49)
        $this->assertEquals(49, $this->egg->fresh()->stock_quantity);
        // 3. Bangsilog remains UNTOUCHED at 15
        $this->assertEquals(15, $this->bangsilog->fresh()->stock_quantity);

        // Verify inventory movement events
        $this->assertDatabaseHas('inventory_events', [
            'pos_item_id' => $this->chicksilog->id,
            'event_type' => 'walkin_pos',
            'quantity_change' => -1,
            'balance_after' => 19,
        ]);

        $this->assertDatabaseHas('inventory_events', [
            'pos_item_id' => $this->egg->id,
            'event_type' => 'recipe_ingredient',
            'quantity_change' => -1,
            'balance_after' => 49,
        ]);
    }

    public function test_non_egg_dish_order_does_not_deduct_egg(): void
    {
        // Ordering French Fries
        $response = $this->actingAs($this->cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos',
            'payment_method' => 'cash',
            'cash_tendered' => 10000,
            'items' => [
                ['id' => $this->fries->id, 'qty' => 5],
            ],
        ]);

        $response->assertRedirect(route('pos.index'));

        // French Fries decremented, Egg and Chicksilog untouched
        $this->assertEquals(25, $this->fries->fresh()->stock_quantity);
        $this->assertEquals(50, $this->egg->fresh()->stock_quantity);
        $this->assertEquals(20, $this->chicksilog->fresh()->stock_quantity);
    }

    public function test_order_fails_when_dish_stock_is_insufficient(): void
    {
        // Attempting to order 25x Chicksilog when only 20 are available
        $response = $this->actingAs($this->cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos',
            'payment_method' => 'cash',
            'cash_tendered' => 10000,
            'items' => [
                ['id' => $this->chicksilog->id, 'qty' => 25],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Insufficient stock for Chicksilog', session('error'));

        // Stocks remained untouched
        $this->assertEquals(20, $this->chicksilog->fresh()->stock_quantity);
        $this->assertEquals(50, $this->egg->fresh()->stock_quantity);
    }

    public function test_order_fails_when_egg_stock_is_insufficient(): void
    {
        $inventoryService = app(InventoryService::class);
        $inventoryService->setStockCount($this->egg->id, 2); // Only 2 eggs left

        // Attempting to order 5x Chicksilog (requires 5 eggs)
        $response = $this->actingAs($this->cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos',
            'payment_method' => 'cash',
            'cash_tendered' => 10000,
            'items' => [
                ['id' => $this->chicksilog->id, 'qty' => 5],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Insufficient egg stock', session('error'));

        // Chicksilog untouched
        $this->assertEquals(20, $this->chicksilog->fresh()->stock_quantity);
        $this->assertEquals(2, $this->egg->fresh()->stock_quantity);
    }

    public function test_exhausted_egg_inventory_makes_silog_dishes_dynamically_unavailable(): void
    {
        $inventoryService = app(InventoryService::class);

        // Chicksilog has 20 in stock and eggs are 50 -> Available
        $this->assertTrue((bool) $this->chicksilog->fresh()->is_available);

        // Exhaust egg stock to 0
        $inventoryService->setStockCount($this->egg->id, 0);

        // Chicksilog still has 20 stock, but dynamically unavailable due to lack of eggs
        $this->assertEquals(20, $this->chicksilog->fresh()->stock_quantity);
        $this->assertFalse((bool) $this->chicksilog->fresh()->is_available);

        // Restock eggs back to 30 -> Automatically becomes available again
        $inventoryService->setStockCount($this->egg->id, 30);
        $this->assertTrue((bool) $this->chicksilog->fresh()->is_available);
    }
}
