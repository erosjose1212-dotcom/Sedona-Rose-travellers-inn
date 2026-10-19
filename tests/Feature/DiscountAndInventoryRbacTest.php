<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\DiscountService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscountAndInventoryRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authoritative_fixed_discount_table()
    {
        // Classic Room: 3h (DC: 40, Senior: 79), 12h (DC: 55, Senior: 195)
        $this->assertEquals(40.00, DiscountService::getDiscountAmount('dc', 'Classic Room', '3h'));
        $this->assertEquals(79.00, DiscountService::getDiscountAmount('senior', 'Classic Room', '3h'));
        $this->assertEquals(79.00, DiscountService::getDiscountAmount('pwd', 'Classic Room', '3h'));
        $this->assertEquals(55.00, DiscountService::getDiscountAmount('dc', 'Classic Room', '12h'));
        $this->assertEquals(195.00, DiscountService::getDiscountAmount('senior', 'Classic Room', '12h'));

        // VIP Suite Room: 3h (DC: 65, Senior: 139), 24h (DC: 115, Senior: 460)
        $this->assertEquals(65.00, DiscountService::getDiscountAmount('dc', 'VIP Suite Room', '3h'));
        $this->assertEquals(139.00, DiscountService::getDiscountAmount('senior', 'VIP Suite Room', '3h'));
        $this->assertEquals(115.00, DiscountService::getDiscountAmount('dc', 'VIP Suite Room', '24h'));
        $this->assertEquals(460.00, DiscountService::getDiscountAmount('senior', 'VIP Suite Room', '24h'));
    }

    public function test_cashier_can_apply_fixed_discount_to_active_folio()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::where('number', '1')->first(); // VIP Suite Room, 3h = 695

        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Senior Citizen Guest',
            'rate_tier' => '3h',
            'headcount' => 1,
        ]);

        $folio = Folio::where('room_id', $room->id)->where('status', 'active')->first();
        $this->assertNotNull($folio);
        $this->assertEquals(695.00, (float) $folio->gross_total);

        // Apply Senior Citizen Fixed Discount (VIP 3h = ₱139.00 discount)
        $resp = $this->actingAs($cashier)->post(route('folios.discount', $folio->id), [
            'discount_type' => 'senior',
            'discount_id_ref' => 'OSCA-199201',
        ]);

        $resp->assertRedirect(route('dashboard'));
        $folio->refresh();

        $this->assertEquals('SENIOR', $folio->discount_type);
        $this->assertEquals('OSCA-199201', $folio->discount_id_ref);
        $this->assertEquals(139.00, (float) $folio->discount_amount);
        $this->assertEquals(556.00, (float) $folio->net_total); // 695 - 139 = 556
    }

    public function test_inventory_atomic_decrement_and_auto_disable()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()]);
        $item = PosItem::create([
            'name' => 'Test Bottled Soda',
            'category' => 'Drinks',
            'price' => 50.00,
            'stock_quantity' => 2,
            'is_tracked' => true,
            'reorder_level' => 1,
            'is_available' => true,
            'kitchen_hours_only' => false,
            'sort_order' => 1,
        ]);

        // Place Walk-in POS Order for 2 units
        $orderResp = $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'walkin_pos',
            'items' => [
                ['id' => $item->id, 'qty' => 2],
            ],
            'payment_method' => 'cash',
            'cash_tendered' => 10000,
        ]);

        $orderResp->assertRedirect(route('pos.index'));
        $item->refresh();

        $this->assertEquals(0, $item->stock_quantity);
        $this->assertFalse($item->is_available); // auto-disabled when stock hits 0

        // Check audit ledger recorded event
        $this->assertDatabaseHas('inventory_events', [
            'pos_item_id' => $item->id,
            'event_type' => 'walkin_pos',
            'quantity_change' => -2,
            'balance_after' => 0,
        ]);
    }

    public function test_admin_shift_recount_and_cashier_movement_csv_export()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $item = PosItem::first();

        // Perform batch recount
        $recountResp = $this->actingAs(User::where('email', 'admin1@sedonapms.com')->firstOrFail())->post(route('inventory.recount'), ['operation_key' => (string) Str::uuid(),
            'counts' => [
                $item->id => 25,
            ],
            'notes' => 'End of shift audit recount',
        ]);

        $recountResp->assertRedirect();
        $this->assertEquals(25, $item->fresh()->stock_quantity);

        // Export movement CSV
        $csvResp = $this->actingAs($cashier)->get(route('inventory.export-csv'));
        $csvResp->assertStatus(200);
        $this->assertStringContainsString('text/csv', $csvResp->headers->get('content-type'));
        $this->assertStringContainsString('Date Time', $csvResp->getContent());
        $this->assertStringContainsString('End of shift audit recount', $csvResp->getContent());
    }

    public function test_shift_disbursement_and_drawer_reconciliation()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $shift = Shift::whereNull('closed_at')->first();
        if (! $shift) {
            $shift = Shift::create([
                'opened_by' => $cashier->id,
                'shift_type' => 'day',
                'shift_date' => now()->toDateString(),
                'opened_at' => now(),
                'opening_float' => 1000.00,
                'cash_total' => 1000.00,
                'gross_revenue' => 0.00,
                'room_revenue' => 0.00,
                'kitchen_revenue' => 0.00,
                'total_expenses' => 0.00,
                'expected_cash' => 1000.00,
                'cash_variance' => 0.00,
            ]);
        }

        // Cashier logs a shift operational expense (payout from drawer)
        $expResp = $this->actingAs($cashier)->post(route('shifts.expense.store'), ['operation_key' => (string) Str::uuid(),
            'category' => 'supplies',
            'description' => 'Pantry tissue paper bulk purchase',
            'amount' => 450.00,
            'receipt_reference' => 'OR-7721',
            'notes' => 'Authorized by front desk supervisor',
        ]);

        $expResp->assertRedirect(route('shifts.index'));
        $shift->refresh();
        $this->assertEquals(450.00, (float) $shift->total_expenses);

        // Cashier closes shift with physical denomination count
        $closeResp = $this->actingAs($cashier)->post(route('shifts.close', $shift->id), [
            'denominations' => [
                '1000' => 5,
                '500' => 2,
                'coins' => 100,
            ],
            'handoff_notes' => 'Evening turnover completed cleanly',
        ]);

        $closeResp->assertRedirect(route('shifts.index'));
        $shift->refresh();

        $this->assertNotNull($shift->closed_at);
        $this->assertEquals(6100.00, (float) $shift->cash_total); // 5000 + 1000 + 100 = 6100
        $this->assertEquals('Evening turnover completed cleanly', $shift->handoff_notes);
    }

    public function test_master_pricing_editor_role_gating()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $kitchen = User::where('email', 'kitchen1@sedonapms.com')->first();
        $admin = User::where('email', 'admin1@sedonapms.com')->first();
        $owner = User::where('email', 'owner@sedonapms.com')->first();

        // Cashier is forbidden (403)
        $this->actingAs($cashier)->get(route('admin.pricing.index'))->assertStatus(403);
        $this->actingAs($cashier)->post(route('admin.pricing.update'))->assertStatus(403);

        // Kitchen is forbidden (403)
        $this->actingAs($kitchen)->get(route('admin.pricing.index'))->assertStatus(403);

        // Admin and Owner have access (200)
        $this->actingAs($admin)->get(route('admin.pricing.index'))->assertStatus(200);
        $this->actingAs($owner)->get(route('admin.pricing.index'))->assertStatus(200);
    }

    public function test_staff_accounts_and_password_resets_strictly_owner()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $admin = User::where('email', 'admin1@sedonapms.com')->first();
        $owner = User::where('email', 'owner@sedonapms.com')->first();

        // Cashier cannot access user management (403)
        $this->actingAs($cashier)->get(route('admin.users.index'))->assertStatus(403);

        // Admin can view the directory (200), but CANNOT create accounts (403)
        $this->actingAs($admin)->get(route('admin.users.index'))->assertStatus(200);
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Unauthorized Staff',
            'email' => 'hack@sedonapms.com',
            'role' => 'cashier',
            'password' => 'secret123',
        ])->assertStatus(403);

        // Owner can provision new staff account (302 redirect)
        $ownerCreateResp = $this->actingAs($owner)->post(route('admin.users.store'), [
            'name' => 'New Cashier Test',
            'email' => 'newcashier@sedonapms.com',
            'role' => 'cashier',
            'password' => 'temporaryPass123',
        ]);

        $ownerCreateResp->assertRedirect();
        $newUser = User::where('email', 'newcashier@sedonapms.com')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->hasRole('cashier'));

        // Owner can reset password
        $ownerResetResp = $this->actingAs($owner)->post(route('admin.users.reset-password', $newUser->id), [
            'password' => 'newBrandNewPassword',
        ]);
        $ownerResetResp->assertRedirect();

        // Owner cannot delete themselves
        $selfDeleteResp = $this->actingAs($owner)->delete(route('admin.users.destroy', $owner->id));
        $selfDeleteResp->assertRedirect();
        $this->assertNotNull(User::find($owner->id));

        // Owner can deactivate another user while retaining their history
        $deleteResp = $this->actingAs($owner)->delete(route('admin.users.destroy', $newUser->id));
        $deleteResp->assertRedirect();
        $this->assertFalse(User::findOrFail($newUser->id)->is_active);
    }
}
