<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutSecurityAndDepositLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Room $roomClassic;

    protected Room $room12Staff;

    protected PosItem $eggItem;

    protected PosItem $tapsilog;

    protected PosItem $calamares;

    protected PosItem $sisigEgg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));

        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'pau@sedonapms.com')->first() ?? User::first();

        $this->roomClassic = Room::where('type', 'Classic Room')->first() ?? Room::create([
            'number' => '1', 'floor' => 1, 'name' => 'Classic 1', 'type' => 'Classic Room',
            'status' => 'available', 'is_staff_quarters' => false,
            'base_rate_3h' => 395, 'base_rate_6h' => 790, 'base_rate_12h' => 1195,
            'base_rate_24h' => 2100, 'base_rate_promo' => 995,
        ]);

        $this->room12Staff = Room::where('number', '12')->first() ?? Room::create([
            'number' => '12', 'floor' => 2, 'name' => 'Staff Quarters', 'type' => 'Staff Quarters',
            'status' => 'available', 'is_staff_quarters' => true,
            'base_rate_3h' => 0, 'base_rate_6h' => 0, 'base_rate_12h' => 0,
            'base_rate_24h' => 0, 'base_rate_promo' => 0,
        ]);

        $this->eggItem = PosItem::firstOrCreate(
            ['name' => 'Egg (Fried/Boiled)'],
            ['category' => 'Kitchen Extras', 'price' => 20.00, 'stock_quantity' => 100, 'is_tracked' => true, 'is_available' => true]
        );

        $this->tapsilog = PosItem::firstOrCreate(
            ['name' => 'Tapsilog'],
            ['category' => 'Breakfast', 'price' => 160.00, 'stock_quantity' => 50, 'is_tracked' => true, 'is_available' => true]
        );

        $this->calamares = PosItem::firstOrCreate(
            ['name' => 'Calamares'],
            ['category' => 'Favorites', 'price' => 180.00, 'stock_quantity' => 30, 'is_tracked' => true, 'is_available' => true]
        );

        $this->sisigEgg = PosItem::firstOrCreate(
            ['name' => 'Sizzling Sisig w/ Egg'],
            ['category' => 'Favorites', 'price' => 230.00, 'stock_quantity' => 25, 'is_tracked' => true, 'is_available' => true]
        );
    }

    /**
     * Test 1: Relational Egg Inventory Deduction for Silog, Calamares, and w/ Egg dishes.
     */
    public function test_relational_egg_inventory_deduction(): void
    {
        $inventoryService = app(InventoryService::class);
        $inventoryService->setStockCount($this->eggItem->id, 100);
        $this->tapsilog->update(['stock_quantity' => 50]);
        $this->calamares->update(['stock_quantity' => 15]);
        $this->sisigEgg->update(['stock_quantity' => 10]);

        // Ordering 2x Tapsilog should decrement Tapsilog by 2 (50 -> 48) AND Egg by 2 (100 -> 98)
        $inventoryService->atomicDecrementStock($this->tapsilog->id, 2, 'sold', 'REF-001', $this->cashier->id);

        $this->assertEquals(48, $this->tapsilog->fresh()->stock_quantity);
        $this->assertEquals(98, $this->eggItem->fresh()->stock_quantity);

        // Ordering 3x Calamares should decrement Calamares by 3 (15 -> 12) AND Egg by 3 (98 -> 95)
        // Tapsilog stock remains independent at 48
        $inventoryService->atomicDecrementStock($this->calamares->id, 3, 'sold', 'REF-002', $this->cashier->id);

        $this->assertEquals(12, $this->calamares->fresh()->stock_quantity);
        $this->assertEquals(95, $this->eggItem->fresh()->stock_quantity);
        $this->assertEquals(48, $this->tapsilog->fresh()->stock_quantity);

        // Ordering 1x Sizzling Sisig w/ Egg should decrement Sisig by 1 (10 -> 9) AND Egg by 1 (95 -> 94)
        $inventoryService->atomicDecrementStock($this->sisigEgg->id, 1, 'sold', 'REF-003', $this->cashier->id);

        $this->assertEquals(9, $this->sisigEgg->fresh()->stock_quantity);
        $this->assertEquals(94, $this->eggItem->fresh()->stock_quantity);
        $this->assertEquals(48, $this->tapsilog->fresh()->stock_quantity);

        // Ordering Egg directly should decrement Egg by 1 without duplicate/recursive deduction
        $inventoryService->atomicDecrementStock($this->eggItem->id, 1, 'sold', 'REF-004', $this->cashier->id);
        $this->assertEquals(93, $this->eggItem->fresh()->stock_quantity);
        $this->assertEquals(48, $this->tapsilog->fresh()->stock_quantity);

        // Verify inventory events recorded
        $this->assertDatabaseHas('inventory_events', [
            'pos_item_id' => $this->eggItem->id,
            'event_type' => 'recipe_ingredient',
            'quantity_change' => -2,
        ]);
    }

    /**
     * Test 1b: Each breakfast item has independent stock, plus relational egg deduction when ordered.
     */
    public function test_all_breakfast_items_have_independent_stock_and_deduct_eggs(): void
    {
        $inventoryService = app(InventoryService::class);

        $egg = PosItem::where('name', 'Egg (Fried/Boiled)')->first();
        $bangsilog = PosItem::firstOrCreate(['name' => 'Bangsilog'], ['category' => 'Breakfast', 'price' => 150, 'stock_quantity' => 15, 'is_tracked' => true, 'is_available' => true]);
        $chicksilog = PosItem::firstOrCreate(['name' => 'Chicksilog'], ['category' => 'Breakfast', 'price' => 150, 'stock_quantity' => 20, 'is_tracked' => true, 'is_available' => true]);
        $tapsilog = PosItem::firstOrCreate(['name' => 'Tapsilog'], ['category' => 'Breakfast', 'price' => 160, 'stock_quantity' => 25, 'is_tracked' => true, 'is_available' => true]);

        // Restock Egg to 40
        $inventoryService->setStockCount($egg->id, 40, 'restock');
        $inventoryService->setStockCount($bangsilog->id, 15, 'restock');
        $inventoryService->setStockCount($chicksilog->id, 20, 'restock');
        $inventoryService->setStockCount($tapsilog->id, 25, 'restock');

        $this->assertEquals(40, $egg->fresh()->stock_quantity);
        $this->assertEquals(15, $bangsilog->fresh()->stock_quantity);
        $this->assertEquals(20, $chicksilog->fresh()->stock_quantity);
        $this->assertEquals(25, $tapsilog->fresh()->stock_quantity);

        // Order 4x Bangsilog -> Bangsilog stock becomes 11, Egg stock becomes 36. Chicksilog & Tapsilog untouched.
        $inventoryService->atomicDecrementStock($bangsilog->id, 4, 'sold', 'TEST-B01', $this->cashier->id);

        $this->assertEquals(36, $egg->fresh()->stock_quantity);
        $this->assertEquals(11, $bangsilog->fresh()->stock_quantity);
        $this->assertEquals(20, $chicksilog->fresh()->stock_quantity);
        $this->assertEquals(25, $tapsilog->fresh()->stock_quantity);

        // Order 5x Chicksilog -> Chicksilog stock becomes 15, Egg stock becomes 31. Bangsilog untouched.
        $inventoryService->atomicDecrementStock($chicksilog->id, 5, 'sold', 'TEST-C01', $this->cashier->id);

        $this->assertEquals(31, $egg->fresh()->stock_quantity);
        $this->assertEquals(11, $bangsilog->fresh()->stock_quantity);
        $this->assertEquals(15, $chicksilog->fresh()->stock_quantity);
        $this->assertEquals(25, $tapsilog->fresh()->stock_quantity);

        // Adjusting Tapsilog stock via setStockCount operates independently
        $inventoryService->setStockCount($tapsilog->id, 30, 'adjustment');

        $this->assertEquals(30, $tapsilog->fresh()->stock_quantity);
        $this->assertEquals(31, $egg->fresh()->stock_quantity);
        $this->assertEquals(15, $chicksilog->fresh()->stock_quantity);

        // Zero out egg stock -> all breakfast items dynamically become unavailable because eggs are exhausted
        $inventoryService->setStockCount($egg->id, 0, 'adjustment');

        $this->assertEquals(0, $egg->fresh()->stock_quantity);
        $this->assertFalse((bool) $egg->fresh()->is_available);
        $this->assertEquals(11, $bangsilog->fresh()->stock_quantity);
        $this->assertFalse((bool) $bangsilog->fresh()->is_available); // Dynamic egg exhaustion check
        $this->assertEquals(15, $chicksilog->fresh()->stock_quantity);
        $this->assertFalse((bool) $chicksilog->fresh()->is_available);
    }

    /**
     * Test 2: Room 12 (Permanent Employee Quarters) Ordering in POS and Dashboard Add-On.
     */
    public function test_room_12_staff_quarters_ordering(): void
    {
        // View POS index as cashier - Room 12 must be in occupiedRooms dropdown
        $posResponse = $this->actingAs($this->cashier)->get(route('pos.index'));
        $posResponse->assertOk();
        $posResponse->assertSee('Permanent Employee Quarters');

        // Place a POS room_charge order for Room 12
        $orderData = [
            'order_type' => 'room_charge',
            'room_id' => $this->room12Staff->id,
            'items' => [
                ['id' => $this->tapsilog->id, 'qty' => 1],
            ],
        ];

        $postOrder = $this->actingAs($this->cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()] + $orderData);
        $postOrder->assertRedirect(route('pos.index'));

        // Staff folio should now exist and hold the order
        $staffFolio = $this->room12Staff->activeFolio;
        $this->assertNotNull($staffFolio);
        $this->assertEquals(0.00, (float) $staffFolio->room_charge);
        $this->assertNotEmpty($staffFolio->pos_items);
        $this->assertEquals('Tapsilog', $staffFolio->pos_items[0]['name']);

        // Dashboard Available Rooms table must have "+ Add On" button for Room 12
        $dashResponse = $this->actingAs($this->cashier)->get(route('dashboard'));
        $dashResponse->assertOk();
        $dashResponse->assertSee('+ Add On');
        $dashResponse->assertSee('Staff Quarters');
    }

    /**
     * Test 3: Checkout Page UI Lockdown & Stay Extension (Xtend) Visibility.
     */
    public function test_checkout_page_lockdown_and_xtend_visibility(): void
    {
        $guest = Guest::create(['name' => 'Honesto Guest', 'contact' => '09123456789', 'headcount' => 2]);
        $folio = Folio::create([
            'room_id' => $this->roomClassic->id,
            'guest_id' => $guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-TEST01',
            'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(5),
            'expected_checkout_at' => now()->subHours(2),
            'status' => 'active',
            'extra_hours' => 2, // 2 hours extended via Xtend
            'room_charge' => 395.00,
            'security_deposit' => 500.00,
            'deposit_status' => 'held',
            'discount_amount' => 79.00,
            'discount_type' => 'SENIOR',
            'discount_id_ref' => 'OSCA-9921',
            'pos_items' => [
                ['name' => 'Mineral Water', 'qty' => 2, 'price' => 25.00, 'subtotal' => 50.00],
            ],
        ]);

        $response = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
        $response->assertOk();

        // 1. Check tamper-proof readonly styles on calculated inputs
        $response->assertSee('pointer-events: none', false);
        $response->assertSee('readonly', false);

        // 2. Check Xtend display
        $response->assertSee('XTEND / EXTENSIONS:');
        $response->assertSee('+2 Hours');
        $response->assertSee('Stay Extension (+2h Xtend @ ₱130.00/hr)');

        // 3. Check Security Deposit Ledger section
        $response->assertSee('SECURITY DEPOSIT LEDGER &amp; TRUST ACCOUNT', false);
        $response->assertSee('₱500.00');

        // 4. Check Total Discount Deduction is locked to fixed amount with badge
        $response->assertSee('SENIOR (OSCA-9921)');
        $response->assertSee('-₱79.00');

        // 5. Check GCash Amount Paid input exists
        $response->assertSee('name="gcash_amount"', false);
    }

    /**
     * Test 4: Checkout Server-Side Tamper Proof Recalculation & GCash Split Settlement.
     */
    public function test_checkout_server_side_recalculation_and_split_payment(): void
    {
        $guest = Guest::create(['name' => 'Tamper Test Guest', 'contact' => '09120000000', 'headcount' => 1]);
        $folio = Folio::create([
            'room_id' => $this->roomClassic->id,
            'guest_id' => $guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-TAMPER',
            'rate_tier' => '3h',
            'checked_in_at' => now()->subMinutes(170),
            'expected_checkout_at' => now()->addMinutes(10),
            'status' => 'active',
            'extra_hours' => 0,
            'room_charge' => 395.00,
            'security_deposit' => 100.00,
            'deposit_status' => 'held',
            'discount_amount' => 40.00, // Fixed DC discount
            'discount_type' => 'DC',
            'pos_items' => [],
        ]);

        // Attempt to tamper with discount and total pay in POST request
        $tamperedPayload = [
            'payment_method' => 'split',
            'cash_tendered' => 200.00,
            'gcash_amount' => 100.00,
            'gcash_reference' => 'GCASH-REF-8899',
            'total_discount' => 300.00,      // Tampered discount!
            'total_amount_to_pay' => 10.00,  // Tampered balance!
        ];

        $this->actingAs($this->cashier)->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 5000])->assertSessionHasNoErrors();
        $checkoutResponse = $this->post(route('checkout.submit', $folio->id), ['operation_key' => (string) Str::uuid()] + $tamperedPayload);
        $checkoutResponse->assertRedirect(route('dashboard'));

        $folio->refresh();

        // Server must enforce real discount (₱40.00), not tampered ₱300
        $this->assertEquals(40.00, (float) $folio->discount_amount);

        // Calculation: 395 (room) - 40 (discount) - 100 (deposit) = 255 balance
        $this->assertEquals(255.00, (float) $folio->net_total);
        $this->assertEquals('checked_out', $folio->status);
        $this->assertEquals('applied_to_bill', $folio->deposit_status);
        $this->assertEquals('split', $folio->payment_method);
        $this->assertEquals(200.00, (float) $folio->cash_tendered);
        $this->assertEquals(100.00, (float) $folio->gcash_amount);
        $this->assertEquals('GCASH-REF-8899', $folio->gcash_reference);

        // Remaining cash due after ₱100 GCash = ₱155. Cash tendered was ₱200, so change = ₱45
        $this->assertEquals(45.00, (float) $folio->change_due);
    }

    /**
     * Test 5: Deposit Slip 80mm Printout (`/folios/{folio}/deposit-slip`).
     */
    public function test_deposit_slip_printout_banner_and_content(): void
    {
        $guest = Guest::create(['name' => 'Maria Makiling', 'contact' => '09121111111']);
        $folio = Folio::create([
            'room_id' => $this->roomClassic->id,
            'guest_id' => $guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-998877',
            'rate_tier' => '12h',
            'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(12),
            'status' => 'active',
            'security_deposit' => 500.00,
            'deposit_payment_method' => 'cash',
            'deposit_status' => 'held',
        ]);

        $response = $this->actingAs($this->cashier)->get(route('folios.deposit_slip', $folio->id));
        $response->assertOk();
        $response->assertSee('SECURITY DEPOSIT LEDGER', false);
        $response->assertSee('SECURITY DEPOSIT RECEIPT', false);
        $response->assertSee('DEP-SCTI-998877');
        $response->assertSee('₱500.00');
        $response->assertSee('Maria Makiling');
    }

    /**
     * Test 6: Order Slip POS-Only Itemization (`/folios/{folio}/orderslip`).
     */
    public function test_orderslip_prints_only_pos_and_addon_items(): void
    {
        $guest = Guest::create(['name' => 'Dining Guest', 'contact' => '09122222222']);
        $folio = Folio::create([
            'room_id' => $this->roomClassic->id,
            'guest_id' => $guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-DINING',
            'rate_tier' => '6h',
            'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(6),
            'status' => 'active',
            'room_charge' => 790.00,
            'extra_hours' => 3,
            'pos_items' => [
                ['name' => 'Tapsilog', 'qty' => 2, 'price' => 160.00, 'subtotal' => 320.00],
                ['name' => 'San Mig Light Beer (330ml)', 'qty' => 1, 'price' => 90.00, 'subtotal' => 90.00],
            ],
        ]);

        $response = $this->actingAs($this->cashier)->get(route('folios.orderslip', $folio->id));
        $response->assertOk();

        // Must display POS items and total
        $response->assertSee('FOOD, BEVERAGE &amp; AMENITIES', false);
        $response->assertSee('Tapsilog');
        $response->assertSee('San Mig Light Beer (330ml)');
        $response->assertSee('410.00'); // 320 + 90

        // Must NOT include room lodging base charge or excess overtime
        $response->assertDontSee('Room Base (6h)');
        $response->assertDontSee('Excess Hours (3h)');
    }

    /**
     * Test 7: Shift Expected Drawer Cash Deducts Cash Expenses.
     */
    public function test_shift_expected_drawer_cash_deducts_cash_expenses(): void
    {
        // Clean out pre-seeded expenses and folios for exact formula validation
        Expense::query()->delete();
        Folio::query()->delete();
        Order::query()->delete();

        $shift = Shift::whereNull('closed_at')->first();
        if (! $shift) {
            $shift = Shift::create([
                'opened_by' => $this->cashier->id,
                'shift_type' => 'day',
                'shift_date' => now()->toDateString(),
                'opened_at' => now()->subHours(4),
                'opening_float' => 5000.00,
                'cash_total' => 5000.00,
                'gross_revenue' => 0,
                'room_revenue' => 0,
                'kitchen_revenue' => 0,
                'total_expenses' => 0,
                'expected_cash' => 5000.00,
                'cash_variance' => 0,
            ]);
        } else {
            $shift->update([
                'opening_float' => 5000.00,
                'opened_at' => now()->subHours(4),
                'total_expenses' => 0,
                'cash_total' => 5000.00,
            ]);
        }

        // Cash sale of ₱1,000
        $guest = Guest::create(['name' => 'Cash Guest', 'contact' => '09123333333']);
        Folio::create([
            'room_id' => $this->roomClassic->id,
            'guest_id' => $guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-CASH01',
            'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(2),
            'expected_checkout_at' => now()->subHours(1),
            'checked_out_at' => now(),
            'status' => 'checked_out',
            'room_charge' => 1000.00,
            'net_total' => 1000.00,
            'payment_method' => 'cash',
            'cash_tendered' => 1000.00,
            'change_due' => 0.00,
        ]);

        // Cash expense payout of ₱350
        Expense::create([
            'shift_id' => $shift->id,
            'user_id' => $this->cashier->id,
            'voucher_number' => 'EXP-TEST-001',
            'expense_date' => now()->toDateString(),
            'category' => 'Supplies',
            'description' => 'Disinfectant Spray & Paper Towels',
            'amount' => 350.00,
            'payment_source' => 'cash_drawer',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->cashier)->get(route('shifts.index'));
        $response->assertOk();

        // Formula: 5000 (float) + 1000 (cash) - 350 (expenses) = 5650.00
        $response->assertSee('₱5,650.00');
        $response->assertSee('[Float + Cash Inflow - Cash Expenses]');
    }

    public function test_cashier_settles_using_admin_corrected_time_and_approved_room_rate(): void
    {
        $guest = Guest::create([
            'name' => 'Editable Time Guest',
            'phone' => '09123456789',
            'headcount' => 2,
        ]);

        $folio = Folio::create([
            'room_id' => $this->roomClassic->id,
            'guest_id' => $guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => Folio::generateTransactionId(),
            'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(2),
            'expected_checkout_at' => now()->addHour(),
            'status' => 'active',
            'room_charge' => 395.00,
            'gross_total' => 395.00,
            'net_total' => 395.00,
        ]);

        // The checkout screen displays recorded time and rate.
        $response = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
        $response->assertOk();
        $response->assertSee('name="checked_in_at"', false);
        $response->assertSee('name="room_charge"', false);

        // RA corrects declaration time; the base rate uses an independent approval.
        $newCheckIn = now()->subMinutes(290)->format('Y-m-d H:i:s');
        $this->actingAs($this->cashier)->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 5000])->assertSessionHasNoErrors();
        $this->post(route('approvals.store'), ['operation_key' => (string) Str::uuid(), 'kind' => 'folio_override', 'source_id' => $folio->id, 'payload' => ['room_charge' => 450], 'reason' => 'Correct recorded rate'])->assertRedirect()->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $this->actingAs($admin)->post('/admin/check-in-times/'.$folio->id, [
            'operation_key' => (string) Str::uuid(), 'expected_checked_in_at' => $folio->fresh()->checked_in_at->toIso8601String(),
            'checked_in_at' => $newCheckIn, 'reason' => 'RA declared the guest before the cashier entered the stay.',
        ])->assertSessionHasNoErrors();
        $submitResponse = $this->actingAs($this->cashier)->post(route('checkout.submit', $folio->id), ['operation_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'cash_tendered' => 1000.00,
            'checked_in_at' => $newCheckIn,
            'room_charge' => 450.00,
        ]);

        $submitResponse->assertRedirect(route('dashboard'));

        $folio->refresh();
        $this->assertEquals(450.00, (float) $folio->room_charge);
        $this->assertEquals(Carbon::parse($newCheckIn)->format('Y-m-d H:i'), $folio->checked_in_at->format('Y-m-d H:i'));
        // 5 hours actual stay - 3 hours tier = 2 excess hours = 2 * 130 = 260 surcharge
        // Total charge = 450 + 260 = 710
        $this->assertEquals(710.00, (float) $folio->gross_total);
    }
}
