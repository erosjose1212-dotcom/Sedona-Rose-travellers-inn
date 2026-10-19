<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VersionSecurityAndReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setTime(12, 0));
    }

    public function test_front_desk_guest_occupancy_excludes_employee_quarters(): void
    {
        $this->withoutVite();
        Room::where('is_staff_quarters', false)->update(['status' => 'available']);
        Room::where('is_staff_quarters', true)->update(['status' => 'occupied']);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();

        $this->actingAs($cashier)->get(route('dashboard'))->assertOk()->assertViewHas('totalRooms', 31)
            ->assertViewHas('occupiedRooms', 0)->assertViewHas('occupancyRate', 0.0);
    }

    public function test_guest_check_in_report_excludes_cancelled_stays_and_employee_quarters(): void
    {
        $this->withoutVite();
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '8')->firstOrFail();
        foreach (['active', 'checked_out', 'voided'] as $status) {
            Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Report guest '.$status, 'headcount' => 2])->id,
                'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'status' => $status,
                'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(2),
                'checked_out_at' => $status === 'active' ? null : now(), 'room_charge' => 395]);
        }
        $staffRoom = Room::where('is_staff_quarters', true)->firstOrFail();
        Folio::create(['room_id' => $staffRoom->id, 'guest_id' => Guest::create(['name' => 'Employee quarters'])->id,
            'user_id' => $cashier->id, 'transaction_id' => 'STAFF-REPORT-QA', 'rate_tier' => 'promo', 'status' => 'active',
            'checked_in_at' => now(), 'expected_checkout_at' => now()->addYear(), 'room_charge' => 0]);
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();

        $response = $this->actingAs($owner)->get(route('reports.index'))->assertOk();

        $response->assertViewHas('totalFoliosThisWeek', 2);
        $this->assertSame(2, $response->viewData('rooms')->firstWhere('id', $room->id)->folios_count);
        $this->assertSame(0, $response->viewData('rooms')->firstWhere('id', $staffRoom->id)->folios_count);
    }

    public function test_passwordless_login_is_removed_and_failed_logins_are_limited(): void
    {
        $this->get('/quick-login/owner@sedonapms.com')->assertNotFound();
        $this->assertGuest();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.submit'), ['email' => 'unknown@example.com', 'password' => 'incorrect'])
                ->assertSessionHasErrors('email');
        }
        $this->post(route('login.submit'), ['email' => 'unknown@example.com', 'password' => 'incorrect'])->assertStatus(429);
    }

    /** @return array<string, array{string, string}> */
    public static function unauthorizedOperations(): array
    {
        return [
            'display check-in' => ['kiosk@sedonapms.com', 'checkin.store'],
            'display POS' => ['kiosk@sedonapms.com', 'pos.order.store'],
            'display shift' => ['kiosk@sedonapms.com', 'shifts.open'],
            'kitchen check-in' => ['kitchen1@sedonapms.com', 'checkin.store'],
            'kitchen shift' => ['kitchen1@sedonapms.com', 'shifts.open'],
        ];
    }

    #[DataProvider('unauthorizedOperations')]
    public function test_display_and_kitchen_accounts_cannot_write_front_desk_records(string $email, string $routeName): void
    {
        $this->actingAs(User::where('email', $email)->firstOrFail())->post(route($routeName))->assertForbidden();
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_deactivating_a_cashier_preserves_financial_history_and_blocks_login(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $folio = $this->folio(['status' => 'checked_out', 'checked_out_at' => now()]);
        $this->actingAs(User::where('email', 'owner@sedonapms.com')->firstOrFail())
            ->delete(route('admin.users.destroy', $cashier))->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'is_active' => false]);
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'user_id' => $cashier->id]);
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $cashier->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($cashier->fresh())->get(route('pos.index'))->assertRedirect(route('login'));
    }

    public function test_shift_collects_settlements_and_deposit_changes_in_actual_transaction_window(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $shift = Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day',
            'shift_date' => today(), 'opened_at' => now()->subHour(), 'opening_float' => 1000]);
        $active = $this->folio(['cash_tendered' => 900, 'net_total' => 900]);
        PaymentEntry::create(['folio_id' => $active->id, 'shift_id' => $shift->id, 'user_id' => $cashier->id,
            'kind' => 'deposit', 'cash_amount' => 500, 'gcash_amount' => 0, 'posted_at' => now()]);
        PaymentEntry::create(['folio_id' => $active->id, 'shift_id' => $shift->id, 'user_id' => $cashier->id,
            'kind' => 'deposit_refund', 'cash_amount' => -100, 'gcash_amount' => 0, 'posted_at' => now()]);
        $this->folio(['status' => 'checked_out', 'checked_in_at' => now()->subDays(2),
            'checked_out_at' => now(), 'cash_tendered' => 250, 'change_due' => 50]);
        Order::create(['user_id' => $cashier->id, 'transaction_id' => 'POS-SPLIT', 'type' => 'walkin_pos',
            'status' => 'new', 'total' => 100, 'payment_method' => 'split', 'cash_tendered' => 40,
            'gcash_amount' => 60, 'change_due' => 0]);
        Expense::create(['user_id' => $cashier->id, 'shift_id' => $shift->id, 'voucher_number' => 'EXP-TEST',
            'expense_date' => today(), 'category' => 'supplies', 'description' => 'Supplies', 'amount' => 50,
            'payment_source' => 'cash_drawer', 'status' => 'approved']);
        Expense::create(['user_id' => $cashier->id, 'shift_id' => $shift->id, 'voucher_number' => 'EXP-VOID',
            'expense_date' => today(), 'category' => 'supplies', 'description' => 'Voided', 'amount' => 500,
            'payment_source' => 'cash_drawer', 'status' => 'voided']);

        $this->actingAs($cashier)->get(route('shifts.index'))->assertOk()
            ->assertViewHas('cashRevenue', 640.0)->assertViewHas('totalExpenses', 50.0)
            ->assertViewHas('expectedCash', 1590.0);
        $this->get(route('shifts.remittance_slip', $shift))->assertOk()
            ->assertViewHas('cashSales', 640.0)->assertViewHas('gcashSales', 60.0);
    }

    public function test_zero_drawer_count_and_negative_variance_are_preserved_on_print(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->actingAs($cashier)->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 1234])->assertRedirect();
        $shift = Shift::firstOrFail();
        $this->assertSame('1234.00', $shift->opening_float);
        $this->post(route('shifts.close', $shift), ['denominations' => ['1000' => -1]])
            ->assertSessionHasErrors('denominations.1000');
        $this->assertNull($shift->fresh()->closed_at);
        $this->post(route('shifts.close', $shift), ['denominations' => ['coins' => 0]])->assertRedirect();
        $this->get(route('shifts.remittance_slip', $shift))->assertOk()
            ->assertViewHas('actualCash', 0.0)->assertViewHas('expectedCash', 1234.0)->assertViewHas('variance', -1234.0);
        $this->post(route('shifts.close', $shift), ['denominations' => ['1000' => 10]])->assertSessionHasErrors('shift');
        $this->assertSame('0.00', $shift->fresh()->cash_total);
    }

    public function test_second_shift_is_rejected_and_next_shift_can_open_after_close(): void
    {
        config(['sedona.continuous_shift_enabled' => false, 'sedona.shift_handover_enabled' => true]);
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail());
        $this->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 100])->assertRedirect();
        $shift = Shift::firstOrFail();
        $this->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 200])->assertSessionHasErrors('shift');
        $this->assertDatabaseCount('shifts', 1);
        $this->post(route('shifts.close', $shift), ['denominations' => ['100' => 1]])->assertRedirect();
        $this->actingAs(User::where('email', 'raquel@sedonapms.com')->firstOrFail())->post(route('shifts.accept', $shift), ['operation_key' => (string) Str::uuid(), 'accepted_cash' => 100, 'notes' => 'Incoming count confirmed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shifts', 2);
    }

    public function test_financial_pages_and_csv_agree_on_settlement_revenue_and_real_expenses(): void
    {
        $this->folio(['status' => 'checked_out', 'checked_in_at' => now()->subDays(9), 'checked_out_at' => now(),
            'gross_total' => 350, 'room_charge' => 200, 'surcharge_total' => 150, 'extra_persons' => 1,
            'discount_amount' => 50, 'net_total' => 300, 'cash_tendered' => 300]);
        $this->folio(['force_checkout' => true, 'net_total' => 500, 'gross_total' => 500]);
        Order::create(['user_id' => User::where('email', 'pau@sedonapms.com')->firstOrFail()->id,
            'transaction_id' => 'POS-CASH', 'type' => 'walkin_pos', 'status' => 'new',
            'total' => 100, 'payment_method' => 'cash', 'cash_tendered' => 100]);
        Expense::create(['voucher_number' => 'EXP-SUPPLIES', 'expense_date' => today(), 'category' => 'supplies',
            'description' => 'Paper', 'amount' => 25, 'status' => 'approved']);
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.accounting.pnl', ['range' => 'today']))->assertOk()
            ->assertViewHas('grossRevenue', 450.0)->assertViewHas('surchargesRev', 150.0)
            ->assertViewHas('lossSlips', 0.0)->assertViewHas('netRevenueCollected', 400.0)
            ->assertViewHas('totalOpex', 25.0)->assertViewHas('netOperatingProfit', 375.0);
        $this->get(route('reports.index'))->assertOk()->assertViewHas('weekRevenue', 400.0)
            ->assertViewHas('totalOperatingExpenses', 25.0)->assertViewHas('netWeeklyOperatingProfit', 375.0);
        $csv = $this->get(route('admin.accounting.pnl.csv', ['range' => 'today']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"GROSS REVENUE",450.00', $csv);
        $this->assertStringContainsString('"NET OPERATING PROFIT (EBITDA)",375.00', $csv);
        $this->get(route('admin.accounting.losses'))->assertOk()->assertViewHas('totalWrittenOff', 0.0);
    }

    public function test_room_status_cannot_release_an_active_guest(): void
    {
        $folio = $this->folio();
        $folio->room->update(['status' => 'occupied']);
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail())
            ->post(route('rooms.status', $folio->room_id), ['status' => 'available'])->assertSessionHasErrors('status');
        $this->assertSame('occupied', $folio->room->fresh()->status);
    }

    public function test_reseeding_retains_custom_password_rates_occupancy_and_stock(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $cashier->update(['password' => 'UniquePassword-123']);
        $cashier->syncRoles(['admin']);
        $room = Room::where('number', '1')->firstOrFail();
        $room->update(['status' => 'occupied', 'base_rate_6h' => 1234]);
        $item = PosItem::where('is_tracked', true)->firstOrFail();
        $item->update(['stock_quantity' => 7, 'price' => 12.34]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('UniquePassword-123', $cashier->fresh()->password));
        $this->assertTrue($cashier->fresh()->hasRole('admin'));
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame('1234.00', $room->fresh()->base_rate_6h);
        $this->assertSame(7, $item->fresh()->stock_quantity);
        $this->assertSame('12.34', $item->fresh()->price);
    }

    public function test_coin_centavos_are_accepted_without_allowing_fractional_banknote_counts(): void
    {
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail());
        $this->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 12.50])->assertRedirect();
        $shift = Shift::firstOrFail();
        $this->post(route('shifts.close', $shift), ['denominations' => ['100' => 1.5]])
            ->assertSessionHasErrors('denominations.100');
        $this->post(route('shifts.close', $shift), ['denominations' => ['coins' => 12.50]])->assertRedirect();
        $this->assertSame('12.50', $shift->fresh()->cash_total);
        $this->assertSame('0.00', $shift->fresh()->cash_variance);
    }

    public function test_admin_cash_drawer_expenses_require_an_open_unfrozen_shift_and_retain_ownership(): void
    {
        $this->actingAs(User::where('email', 'admin1@sedonapms.com')->firstOrFail());
        $expense = ['operation_key' => (string) Str::uuid(), 'expense_date' => today()->toDateString(), 'category' => 'supplies',
            'description' => 'Drawer supplies', 'amount' => 25, 'payment_source' => 'cash_drawer'];
        $this->post(route('admin.accounting.expenses.store'), $expense)->assertSessionHasErrors('payment_source');
        $this->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 100])->assertRedirect();
        $shift = Shift::firstOrFail();
        $shift->update(['is_frozen' => true]);
        $this->post(route('admin.accounting.expenses.store'), $expense)->assertSessionHasErrors('payment_source');
        $this->assertDatabaseCount('expenses', 0);
        $shift->update(['is_frozen' => false]);
        $this->post(route('admin.accounting.expenses.store'), $expense)->assertRedirect();
        $this->assertDatabaseHas('expenses', ['description' => 'Drawer supplies', 'shift_id' => $shift->id, 'amount' => 25]);
        $this->get(route('shifts.index'))->assertOk()->assertViewHas('expectedCash', 75.0);
    }

    public function test_transactions_at_a_shift_boundary_belong_to_one_shift_in_cash_and_revenue(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $oldShift = Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day',
            'shift_date' => today(), 'opened_at' => now()->subHour(), 'closed_at' => now(), 'opening_float' => 0]);
        $newShift = Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day',
            'shift_date' => today(), 'opened_at' => now(), 'opening_float' => 0]);
        $oldFolio = $this->folio(['status' => 'checked_out', 'checked_out_at' => now(),
            'cash_tendered' => 200, 'room_charge' => 200, 'gross_total' => 200, 'net_total' => 200]);
        PaymentEntry::create(['folio_id' => $oldFolio->id, 'shift_id' => $oldShift->id,
            'user_id' => $cashier->id, 'kind' => 'settlement', 'cash_amount' => 200, 'gcash_amount' => 0, 'posted_at' => now()]);
        foreach ([$oldShift->id => ['OLD-BOUNDARY', 60], $newShift->id => ['NEW-BOUNDARY', 40]] as $shiftId => [$reference, $amount]) {
            Order::create(['user_id' => $cashier->id, 'shift_id' => $shiftId, 'transaction_id' => $reference,
                'type' => 'walkin_pos', 'status' => 'new', 'total' => $amount,
                'payment_method' => 'cash', 'cash_tendered' => $amount]);
        }
        $this->actingAs($cashier)->get(route('shifts.index'))->assertOk()->assertViewHas('cashRevenue', 40.0);
        $this->post(route('shifts.close', $newShift), ['denominations' => ['20' => 2]])->assertRedirect();
        $this->assertSame('40.00', $newShift->fresh()->gross_revenue);
        $this->assertSame('40.00', $newShift->fresh()->kitchen_revenue);
        $this->assertSame('0.00', $newShift->fresh()->room_revenue);
        $this->get(route('shifts.remittance_slip', $oldShift))->assertOk()->assertViewHas('cashSales', 260.0);
        $this->get(route('shifts.remittance_slip', $newShift))->assertOk()->assertViewHas('cashSales', 40.0);
    }

    /** @param array<string, mixed> $attributes */
    private function folio(array $attributes = []): Folio
    {
        return Folio::create([
            'transaction_id' => Folio::generateTransactionId(), 'room_id' => Room::where('number', '1')->firstOrFail()->id,
            'guest_id' => Guest::create(['name' => 'Report Guest'])->id,
            'user_id' => User::where('email', 'pau@sedonapms.com')->firstOrFail()->id,
            'rate_tier' => '3h', 'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(2),
            'status' => 'active', 'room_charge' => 200, 'gross_total' => 200, 'net_total' => 200,
            'payment_method' => 'cash', ...$attributes,
        ]);
    }
}
