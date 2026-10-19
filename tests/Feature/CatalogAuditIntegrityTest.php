<?php

namespace Tests\Feature;

use App\Models\InventoryEvent;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CatalogAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_catalog_creation_records_the_actual_opening_balance_and_subsequent_depletion(string $role): void
    {
        $operator = $this->operator($role);

        $this->actingAs($operator)->post(route('admin.pricing.items.store'), [
            'name' => 'Bottled Lemonade', 'category' => 'Beverages', 'price' => 45.50,
            'is_tracked' => true, 'stock_quantity' => 12, 'reorder_level' => 3, 'is_available' => true,
            'password' => 'not-a-catalog-field',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PosItem::firstOrFail();
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'event_type' => 'opening_balance', 'quantity_change' => 12, 'balance_after' => 12, 'user_id' => $operator->id]);
        $this->assertSame([
            'before' => null,
            'after' => ['name' => 'Bottled Lemonade', 'category' => 'Beverages', 'price' => '45.50', 'is_tracked' => true, 'stock_quantity' => 12, 'reorder_level' => 3, 'kitchen_hours_only' => false, 'is_available' => true, 'sort_order' => 1, 'is_retired' => false],
        ], $this->auditValues('catalog.item_created', $item->id, $operator->id));

        app(InventoryService::class)->consumeStockForOrder([$item->id => 2], 'sold', 'ORDER-TEST-1', $operator->id);

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertSame(10, (int) InventoryEvent::where('pos_item_id', $item->id)->sum('quantity_change'));
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'event_type' => 'sold', 'quantity_change' => -2, 'balance_after' => 10, 'reference_id' => 'ORDER-TEST-1']);
    }

    #[TestWith([0, true])]
    #[TestWith([8, false])]
    public function test_opening_stock_preserves_the_explicit_manual_availability(int $stock, bool $available): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('admin.pricing.items.store'), ['name' => 'Tracked Item', 'category' => 'Beverages', 'price' => 50, 'is_tracked' => true, 'stock_quantity' => $stock, 'is_available' => $available])->assertSessionHasNoErrors();

        $item = PosItem::firstOrFail();
        $this->assertSame($available, (bool) $item->getRawOriginal('is_available'));
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'quantity_change' => $stock, 'balance_after' => $stock]);
    }

    public function test_untracked_item_does_not_invent_an_opening_stock_movement(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('admin.pricing.items.store'), ['name' => 'Untracked Service', 'category' => 'Services', 'price' => 50, 'is_tracked' => false, 'stock_quantity' => 12, 'is_available' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pos_items', ['name' => 'Untracked Service', 'is_tracked' => false, 'stock_quantity' => 0, 'is_available' => true]);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog.item_created', 'user_id' => $operator->id]);
    }

    public function test_rejected_opening_count_rolls_back_the_catalog_item_and_audit(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('admin.pricing.items.store'), ['name' => 'Invalid Opening', 'category' => 'Beverages', 'price' => 50, 'is_tracked' => true, 'stock_quantity' => 100001, 'is_available' => true])->assertSessionHasErrors('stock_quantity');

        $this->assertDatabaseCount('pos_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_catalog_creation_rolls_back_opening_stock_if_audit_persistence_fails(): void
    {
        $operator = $this->operator();
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated catalog audit failure.'));

        $this->actingAs($operator)->post(route('admin.pricing.items.store'), ['name' => 'Incomplete Stock', 'category' => 'Beverages', 'price' => 50,
            'is_tracked' => true, 'stock_quantity' => 12, 'is_available' => true])->assertInternalServerError();

        $this->assertDatabaseCount('pos_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_room_pricing_audit_retains_the_exact_before_and_after_rates(): void
    {
        $operator = $this->operator();
        $room = Room::create(['number' => '1', 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'base_rate_3h' => 395, 'base_rate_4h' => 525, 'base_rate_6h' => 790, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100, 'base_rate_promo' => 995]);

        $this->actingAs($operator)->post(route('admin.pricing.update'), ['room_id' => $room->id, 'base_rate_3h' => 410, 'base_rate_4h' => 540, 'base_rate_6h' => 800, 'base_rate_12h' => 1200, 'base_rate_24h' => 2200, 'base_rate_promo' => 1000, 'password' => 'not-a-rate-field'])->assertSessionHasNoErrors();

        $this->assertSame([
            'before' => ['base_rate_3h' => '395.00', 'base_rate_4h' => '525.00', 'base_rate_6h' => '790.00', 'base_rate_12h' => '1195.00', 'base_rate_24h' => '2100.00', 'base_rate_promo' => '995.00'],
            'after' => ['base_rate_3h' => '410.00', 'base_rate_4h' => '540.00', 'base_rate_6h' => '800.00', 'base_rate_12h' => '1200.00', 'base_rate_24h' => '2200.00', 'base_rate_promo' => '1000.00'],
        ], $this->auditValues('pricing.room_updated', $room->id, $operator->id));
        $this->assertSame('410.00', $room->fresh()->base_rate_3h);
    }

    public function test_pos_pricing_and_retirement_audit_changes_without_altering_stock_history(): void
    {
        $operator = $this->operator();
        $item = PosItem::create(['name' => 'Tracked Soda', 'category' => 'Beverages', 'price' => 25, 'is_tracked' => true, 'stock_quantity' => 5, 'is_available' => true]);

        $this->actingAs($operator)->post(route('admin.pricing.update'), ['pos_item_id' => $item->id, 'price' => 30.50, 'is_available' => false])->assertSessionHasNoErrors();

        $this->assertSame(['before' => ['price' => '25.00', 'is_available' => true], 'after' => ['price' => '30.50', 'is_available' => false]], $this->auditValues('catalog.item_pricing_updated', $item->id, $operator->id));
        $this->assertSame(5, $item->fresh()->stock_quantity);

        $this->delete(route('admin.pricing.items.destroy', $item))->assertSessionHasNoErrors();

        $this->assertSame(['before' => ['is_retired' => false, 'is_available' => false], 'after' => ['is_retired' => true, 'is_available' => false]], $this->auditValues('catalog.item_retired', $item->id, $operator->id));
        $this->assertTrue($item->fresh()->is_retired);
        $this->assertSame(5, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    private function operator(string $role = 'owner'): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate($role, 'web'));

        return $operator;
    }

    /** @return array<string, mixed> */
    private function auditValues(string $event, int $sourceId, int $operatorId): array
    {
        $audit = DB::table('audit_logs')->where('event', $event)->where('auditable_id', $sourceId)->where('user_id', $operatorId)->sole();

        return json_decode($audit->new_values, true, 512, JSON_THROW_ON_ERROR);
    }
}
