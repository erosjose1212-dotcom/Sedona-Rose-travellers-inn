<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\InventoryEvent;
use App\Models\PosItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierStockRestockTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_entering_thirty_adds_to_three_and_retry_does_not_add_twice(): void
    {
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $item = PosItem::create(['name' => 'Restock sample', 'price' => 50, 'category' => 'Beverages', 'is_tracked' => true, 'stock_quantity' => 3]);
        $data = ['operation_key' => (string) Str::uuid(), 'stock_quantity' => 30, 'reason' => 'restock'];

        $this->actingAs($cashier)->post(route('inventory.update-stock', $item), $data)->assertSessionHasNoErrors();
        $this->post(route('inventory.update-stock', $item), $data)->assertSessionHasNoErrors();

        $this->assertSame(33, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'quantity_change' => 30, 'balance_after' => 33]);
    }

    public function test_cashier_cannot_set_counts_or_bypass_restrictions_using_recount_or_approval_routes(): void
    {
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $cashier->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $item = PosItem::create(['name' => 'Protected stock', 'price' => 50, 'category' => 'Beverages', 'is_tracked' => true, 'stock_quantity' => 33]);
        $this->actingAs($cashier);

        $this->post(route('inventory.update-stock', $item), ['operation_key' => (string) Str::uuid(), 'stock_quantity' => 3, 'reason' => 'adjustment'])->assertForbidden();
        $this->post(route('inventory.recount'), ['operation_key' => (string) Str::uuid(), 'counts' => [$item->id => 3]])->assertForbidden();
        $this->post(route('approvals.store'), ['operation_key' => (string) Str::uuid(), 'kind' => 'stock_adjustment', 'source_id' => $item->id,
            'payload' => ['stock_quantity' => 3, 'reason' => 'restock'], 'reason' => 'Attempt to set count'])->assertForbidden();

        $this->assertSame(33, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('approval_requests', 0);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([1.5])]
    #[TestWith([100001])]
    public function test_invalid_delivery_quantities_leave_stock_unchanged(int|float $quantity): void
    {
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $item = PosItem::create(['name' => 'Delivery sample', 'price' => 50, 'category' => 'Beverages', 'stock_quantity' => 3]);

        $this->actingAs($cashier)->post(route('inventory.update-stock', $item), ['operation_key' => (string) Str::uuid(), 'stock_quantity' => $quantity, 'reason' => 'restock'])->assertSessionHasErrors('stock_quantity');

        $this->assertSame(3, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_restock_rejects_retired_items_and_total_stock_overflow(): void
    {
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $item = PosItem::create(['name' => 'Almost full stock', 'price' => 50, 'category' => 'Beverages', 'stock_quantity' => 99999]);
        $this->actingAs($cashier);

        $this->post(route('inventory.update-stock', $item), ['operation_key' => (string) Str::uuid(), 'stock_quantity' => 2, 'reason' => 'restock'])->assertSessionHasErrors('stock_quantity');
        $item->update(['is_retired' => true]);
        $this->post(route('inventory.update-stock', $item), ['operation_key' => (string) Str::uuid(), 'stock_quantity' => 1, 'reason' => 'restock'])->assertSessionHasErrors('stock_quantity');

        $this->assertSame(99999, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_cashier_cannot_execute_a_preexisting_exact_count_request(): void
    {
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $cashier->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $approval = ApprovalRequest::factory()->create(['requested_by' => $cashier->id]);

        $this->actingAs($cashier)->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertForbidden();

        $this->assertSame(10, $approval->source->fresh()->stock_quantity);
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    #[TestWith(['pending'])]
    #[TestWith(['approved'])]
    public function test_cashier_has_no_apply_control_for_older_stock_adjustment_requests(string $status): void
    {
        $this->withoutVite();
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $cashier->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        ApprovalRequest::factory()->create(['requested_by' => $cashier->id, 'status' => $status]);

        $this->actingAs($cashier)->get(route('approvals.index'))->assertOk()
            ->assertDontSee('Apply stock update now')->assertDontSee('Apply approved action')
            ->assertSee('Only an admin or owner can apply this older stock adjustment.');
    }

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_admin_and_owner_can_correct_counts_immediately(string $role): void
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        $item = PosItem::create(['name' => 'Recount sample', 'price' => 50, 'category' => 'Beverages', 'is_tracked' => true, 'stock_quantity' => 33]);

        $this->actingAs($actor)->post(route('inventory.update-stock', $item), ['operation_key' => (string) Str::uuid(), 'stock_quantity' => 30, 'reason' => 'adjustment'])->assertSessionHasNoErrors();

        $this->assertSame(30, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $item->id, 'quantity_change' => -3, 'balance_after' => 30]);
        $this->assertDatabaseHas('approval_requests', ['kind' => 'stock_adjustment', 'status' => 'executed', 'reviewed_by' => null]);
    }

    public function test_cashier_inventory_screen_only_offers_stock_additions(): void
    {
        $this->withoutVite();
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        PosItem::create(['name' => 'Visible sample', 'price' => 50, 'category' => 'Beverages', 'is_tracked' => true, 'stock_quantity' => 3]);

        $this->actingAs($cashier)->get(route('inventory.index'))->assertOk()->assertSee('+ Add Stock')
            ->assertDontSee('id="recountModal"', false)->assertDontSee('<option value="adjustment">', false)
            ->assertSee('Stock 3 + delivery 30 = 33.');
    }

    public function test_failed_restock_ledger_write_rolls_back_and_can_be_retried_once(): void
    {
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $item = PosItem::create(['name' => 'Atomic delivery', 'price' => 50, 'category' => 'Beverages', 'stock_quantity' => 3]);
        $payload = ['operation_key' => (string) Str::uuid(), 'stock_quantity' => 30, 'reason' => 'restock'];
        InventoryEvent::creating(function (): void {
            throw new RuntimeException('Simulated stock ledger failure.');
        });

        try {
            $this->actingAs($cashier)->post(route('inventory.update-stock', $item), $payload)->assertServerError();
        } finally {
            Event::forget('eloquent.creating: '.InventoryEvent::class);
        }

        $this->assertSame(3, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('financial_operations', 0);

        $this->post(route('inventory.update-stock', $item), $payload)->assertSessionHasNoErrors();

        $this->assertSame(33, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
    }
}
