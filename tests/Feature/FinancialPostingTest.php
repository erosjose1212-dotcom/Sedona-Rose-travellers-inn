<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\FinancialSummaryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FinancialPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->actingAs($this->cashier);
        $this->shift = Shift::create(['opened_by' => $this->cashier->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()->subHour(), 'opening_float' => 100]);
    }

    public function test_autosave_and_print_preview_cannot_invent_or_refund_a_deposit(): void
    {
        $folio = $this->folio();
        $this->postJson(route('folios.deposit', $folio), ['security_deposit' => 500, 'apply_deposit' => true])->assertOk()->assertJsonPath('security_deposit', '0.00');
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('financial_operations', 0);
        $this->assertNull($folio->fresh()->deposit_collected_at);
        $this->get(route('folios.receipt', $folio).'?deposit=500')->assertOk()->assertViewHas('deposit', 0);
        $this->get(route('checkout.process', $folio))->assertOk()->assertSee('Record deposit received')->assertSee('name="operation_key"', false);
    }

    public function test_explicit_deposit_retries_record_one_money_movement_even_after_the_drawer_closes(): void
    {
        $folio = $this->folio();
        $data = $this->operation(['amount' => 250, 'payment_method' => 'cash']);
        $this->post(route('folios.deposit.collect', $folio), $data)->assertSessionHasNoErrors();
        $this->shift->update(['closed_at' => now(), 'open_key' => null]);
        $this->post(route('folios.deposit.collect', $folio), $data)->assertSessionHasNoErrors();
        $this->assertSame('250.00', $folio->fresh()->security_deposit);
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertDatabaseCount('financial_operations', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('payment_entries', ['user_id' => $this->cashier->id, 'shift_id' => $this->shift->id, 'cash_amount' => 250, 'kind' => 'deposit']);
    }

    public function test_gcash_deposit_requires_its_own_reference_and_failed_submission_can_retry(): void
    {
        $folio = $this->folio();
        $data = $this->operation(['amount' => 100, 'payment_method' => 'gcash']);
        $this->post(route('folios.deposit.collect', $folio), $data)->assertSessionHasErrors('gcash_reference');
        $this->assertDatabaseCount('financial_operations', 0);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->post(route('folios.deposit.collect', $folio), $data + ['gcash_reference' => 'TRANSFER-001'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payment_entries', ['gcash_amount' => 100, 'gcash_reference' => 'TRANSFER-001']);
    }

    public function test_checkout_retry_returns_the_original_outcome_and_changed_payload_is_rejected(): void
    {
        $folio = $this->folio();
        $data = $this->operation(['payment_method' => 'cash', 'cash_tendered' => 150]);
        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $data['cash_tendered'] = 200;
        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasErrors('operation_key');
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('50.00', $folio->fresh()->change_due);
        $this->assertDatabaseHas('payment_entries', ['kind' => 'settlement', 'cash_amount' => 100]);
    }

    public function test_operation_key_cannot_be_reused_by_another_actor_or_for_another_folio(): void
    {
        $folio = $this->folio();
        $data = $this->operation(['amount' => 100, 'payment_method' => 'cash']);
        $this->post(route('folios.deposit.collect', $folio), $data)->assertSessionHasNoErrors();
        $owner = User::role('owner')->firstOrFail();
        $this->actingAs($owner)->post(route('folios.deposit.collect', $folio), $data)->assertForbidden();
        $this->actingAs($this->cashier)->post(route('folios.deposit.collect', $this->folio()), $data)->assertSessionHasErrors('operation_key');
        $this->assertDatabaseCount('payment_entries', 1);
    }

    public function test_walkin_retry_consumes_stock_once_and_mixed_legacy_totals_are_not_duplicated(): void
    {
        $item = $this->item();
        $data = $this->operation(['order_type' => 'walkin_pos', 'items' => [['id' => $item->id, 'qty' => 2]], 'payment_method' => 'cash', 'cash_tendered' => 250]);
        $this->post(route('pos.order.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('pos.order.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertSame(8, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('payment_entries', ['order_id' => Order::firstOrFail()->id, 'kind' => 'pos_sale', 'cash_amount' => 200]);
        Order::create(['user_id' => $this->cashier->id, 'shift_id' => $this->shift->id, 'transaction_id' => 'LEGACY-POS', 'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 75, 'payment_method' => 'gcash', 'gcash_amount' => 75, 'gcash_reference' => 'LEGACY-REF']);
        $summary = app(FinancialSummaryService::class);
        $this->assertEquals(200, $summary->shift($this->shift)['cashSales']);
        $this->assertEquals(75, $summary->shift($this->shift)['gcashSales']);
        $this->assertEquals(275, $summary->shift($this->shift)['netRevenue']);
        $period = $summary->period(now()->startOfDay(), now()->endOfDay());
        $this->assertEquals(200, $period['cashCollections']);
        $this->assertEquals(75, $period['gcashCollections']);
        $this->get(route('transactions.index'))->assertOk()->assertSee('LEGACY-POS')->assertSee(Order::firstOrFail()->transaction_id);
    }

    public function test_room_charge_retries_do_not_duplicate_stock_or_folio_items(): void
    {
        $folio = $this->folio();
        $item = $this->item();
        $data = $this->operation(['order_type' => 'room_charge', 'room_id' => $folio->room_id, 'folio_id' => $folio->id, 'items' => [['id' => $item->id, 'qty' => 1]]]);
        $this->post(route('pos.order.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('pos.order.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertCount(1, $folio->fresh()->pos_items);
        $this->assertSame(9, $item->fresh()->stock_quantity);
    }

    public function test_quick_addon_retries_do_not_duplicate_stock_or_folio_items(): void
    {
        $folio = $this->folio();
        $item = $this->item();
        $data = $this->operation(['pos_item_id' => $item->id, 'quantity' => 1]);
        $this->post(route('folios.addon', $folio), $data)->assertSessionHasNoErrors();
        $this->post(route('folios.addon', $folio), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, $folio->fresh()->pos_items);
        $this->assertSame(9, $item->fresh()->stock_quantity);
    }

    public function test_date_filters_and_pagination_keep_all_matching_records_accessible(): void
    {
        for ($index = 0; $index < 31; $index++) {
            PaymentEntry::create(['user_id' => $this->cashier->id, 'kind' => 'deposit', 'cash_amount' => 1, 'posted_at' => now()->subMinutes($index)]);
        }
        PaymentEntry::create(['user_id' => $this->cashier->id, 'kind' => 'deposit_refund', 'cash_amount' => -1, 'posted_at' => now()->subDays(2)]);
        $today = now()->toDateString();
        $response = $this->get(route('transactions.index').'?from='.$today.'&to='.$today.'&kind=deposit');
        $response->assertOk()->assertViewHas('transactions', fn ($records) => $records->total() === 31 && $records->count() === 30);
        $this->get(route('transactions.index').'?from='.$today.'&to='.$today.'&kind=deposit&page=2')->assertOk()->assertViewHas('transactions', fn ($records) => $records->count() === 1);
        $this->get(route('transactions.index').'?to='.$today)->assertOk()->assertViewHas('transactions', fn ($records) => $records->total() === 32);
    }

    public function test_deposit_and_refund_print_requests_are_tracked_independently(): void
    {
        $folio = $this->folio();
        $this->post(route('folios.deposit_slip.print', $folio), $this->operation([]))->assertStatus(409);
        $this->post(route('folios.deposit.collect', $folio), $this->operation(['amount' => 150, 'payment_method' => 'cash']))->assertSessionHasNoErrors();
        $key = $this->operation([]);
        $this->post(route('folios.deposit_slip.print', $folio), $key)->assertOk()->assertViewHas('receiptCopy', 1);
        $this->post(route('folios.deposit_slip.print', $folio), $key)->assertOk()->assertViewHas('receiptCopy', 1);
        $this->post(route('checkout.submit', $folio), $this->operation(['cash_tendered' => 0]))->assertSessionHasNoErrors();
        $this->post(route('folios.deposit_refund.print', $folio), $this->operation([]))->assertOk()->assertSee('FIRST RECORDED PRINT REQUEST');
        $this->assertEquals(1, DB::table('audit_logs')->where('event', 'deposit.print_requested')->count());
        $this->assertEquals(1, DB::table('audit_logs')->where('event', 'deposit_refund.print_requested')->count());
    }

    public function test_refund_links_original_deposits_and_retries_do_not_reduce_the_balance_twice(): void
    {
        $folio = $this->folio();
        foreach ([200, 300] as $amount) {
            $this->post(route('folios.deposit.collect', $folio), $this->operation(['amount' => $amount, 'payment_method' => 'cash']))->assertSessionHasNoErrors();
        }
        $this->post(route('checkout.submit', $folio), $this->operation(['apply_deposit' => false, 'cash_tendered' => 100]))->assertSessionHasNoErrors();
        $data = $this->operation(['amount' => 350, 'payment_method' => 'gcash', 'gcash_reference' => 'REFUND-001']);
        $this->post(route('folios.deposit_refund.store', $folio), $data)->assertSessionHasNoErrors();
        $this->post(route('folios.deposit_refund.store', $folio), $data)->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $this->assertSame('350.00', $folio->fresh()->deposit_refunded_amount);
        $this->assertDatabaseCount('payment_entries', 4);
        $this->assertDatabaseCount('payment_refund_allocations', 2);
        $this->assertEquals(350, DB::table('payment_refund_allocations')->sum('amount'));
        $this->assertDatabaseHas('payment_entries', ['kind' => 'deposit_refund', 'gcash_amount' => -350, 'gcash_reference' => 'REFUND-001']);
        $this->post(route('folios.deposit_refund.store', $folio), $this->operation(['amount' => 151, 'payment_method' => 'cash']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payment_entries', 4);
    }

    public function test_refund_of_a_legacy_deposit_preserves_unknown_origin(): void
    {
        $folio = $this->folio(['security_deposit' => 50, 'deposit_status' => 'not_applied']);
        $this->post(route('checkout.submit', $folio), $this->operation(['cash_tendered' => 100]))->assertSessionHasNoErrors();
        $this->post(route('folios.deposit_refund.store', $folio), $this->operation(['amount' => 50, 'payment_method' => 'cash']))->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $this->assertDatabaseCount('payment_refund_allocations', 0);
        $this->get(route('transactions.index'))->assertOk()->assertSee('Original deposit entry unavailable (legacy)');
    }

    public function test_mixed_legacy_and_new_deposit_application_keeps_refund_origin_and_liability_consistent(): void
    {
        $folio = $this->folio(['security_deposit' => 50, 'deposit_status' => 'applied_to_bill']);
        $this->post(route('folios.deposit.collect', $folio), $this->operation(['amount' => 100, 'payment_method' => 'cash']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('checkout.submit', $folio), $this->operation(['cash_tendered' => 0]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('folios.deposit_refund.store', $folio), $this->operation(['amount' => 50, 'payment_method' => 'cash']))->assertRedirect()->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $this->assertEquals(50, DB::table('payment_refund_allocations')->sum('amount'));
        $snapshot = app(BusinessDayService::class)->snapshot(app(BusinessDayService::class)->date());
        $this->assertSame(0, $snapshot['closing_deposit_cents']);
        $this->assertSame(0, $snapshot['closing_legacy_deposit_cents']);
    }

    public function test_audit_failure_rolls_back_money_stock_and_operation_key(): void
    {
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit storage unavailable'));
        $item = $this->item();
        $data = $this->operation(['order_type' => 'walkin_pos', 'items' => [['id' => $item->id, 'qty' => 1]], 'payment_method' => 'cash', 'cash_tendered' => 100]);
        $this->post(route('pos.order.store'), $data)->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('financial_operations', 0);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertSame(10, $item->fresh()->stock_quantity);
    }

    public function test_cashier_history_is_scoped_and_owner_can_search_property_records(): void
    {
        $folio = $this->folio();
        $data = $this->operation(['amount' => 75, 'payment_method' => 'gcash', 'gcash_reference' => 'OWN-TRANSFER']);
        $this->post(route('folios.deposit.collect', $folio), $data)->assertSessionHasNoErrors();
        $other = User::factory()->create();
        PaymentEntry::create(['user_id' => $other->id, 'kind' => 'deposit', 'gcash_amount' => 10, 'posted_at' => now(), 'gcash_reference' => 'OTHER-PRIVATE']);
        $this->get(route('transactions.index'))->assertOk()->assertSee('OWN-TRANSFER')->assertDontSee('OTHER-PRIVATE');
        $this->get(route('transactions.audit'))->assertOk()->assertSee('payment.deposit');
        $owner = User::role('owner')->firstOrFail();
        $this->actingAs($owner)->get(route('transactions.index').'?q=OTHER-PRIVATE')->assertOk()->assertSee('OTHER-PRIVATE')->assertDontSee('OWN-TRANSFER');
        $this->get(route('transactions.index').'?from=invalid')->assertSessionHasErrors('from');
        $kitchen = User::role('kitchen')->firstOrFail();
        $this->actingAs($kitchen)->get(route('transactions.index'))->assertForbidden();
        $this->get(route('transactions.audit'))->assertForbidden();
    }

    public function test_provider_reference_can_allocate_multiple_payments_without_claiming_verification(): void
    {
        $folio = $this->folio();
        foreach ([40, 60] as $amount) {
            $this->post(route('folios.deposit.collect', $folio), $this->operation(['amount' => $amount, 'payment_method' => 'gcash', 'gcash_reference' => 'ONE-TRANSFER']))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('payment_entries', 2);
        $this->assertEquals(100, PaymentEntry::sum('gcash_amount'));
        $this->get(route('transactions.index').'?q=ONE-TRANSFER')->assertOk()->assertSee('Recorded; provider verification pending');
    }

    public function test_receipt_print_requests_have_copy_numbers_and_retry_does_not_increment(): void
    {
        $folio = $this->folio();
        $this->post(route('folios.receipt.print', $folio), $this->operation([]))->assertStatus(409);
        $this->post(route('checkout.submit', $folio), $this->operation(['cash_tendered' => 100]))->assertSessionHasNoErrors();
        $this->get(route('folios.receipt', $folio))->assertOk()->assertSee('PREVIEW — NO PRINT REQUEST RECORDED');
        $key = $this->operation([]);
        $this->post(route('folios.receipt.print', $folio), $key)->assertOk()->assertSee('FIRST RECORDED PRINT REQUEST');
        $this->post(route('folios.receipt.print', $folio), $key)->assertOk()->assertViewHas('receiptCopy', 1);
        $this->post(route('folios.receipt.print', $folio), $this->operation([]))->assertOk()->assertSee('COPY — PRINT REQUEST #2');
        $events = DB::table('audit_logs')->where('event', 'receipt.print_requested')->get();
        $this->assertCount(2, $events);
        $this->assertFalse(json_decode($events->first()->new_values, true)['physical_print_confirmed']);
    }

    public function test_posted_payment_cannot_be_edited_or_deleted_through_the_model(): void
    {
        $entry = PaymentEntry::create(['user_id' => $this->cashier->id, 'kind' => 'deposit', 'cash_amount' => 100, 'posted_at' => now()]);
        try {
            $entry->update(['cash_amount' => 200]);
            $this->fail('Posted payment update was allowed.');
        } catch (\LogicException) {
            $this->assertSame('100.00', $entry->fresh()->cash_amount);
        }
        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_financial_submission_requires_a_stable_operation_key(): void
    {
        $this->postJson(route('folios.deposit.collect', $this->folio()), ['amount' => 20, 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('operation_key');
        $this->assertDatabaseCount('payment_entries', 0);
    }

    /** @param array<string, mixed> $values */
    private function folio(array $values = []): Folio
    {
        $room = Room::where('type', 'Classic Room')->where('status', 'available')->firstOrFail();
        $room->update(['status' => 'occupied']);
        $guest = Guest::create(['name' => 'Posting Test Guest', 'contact' => (string) Str::uuid(), 'headcount' => 1]);

        return Folio::create($values + ['room_id' => $room->id, 'guest_id' => $guest->id, 'user_id' => $this->cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(2), 'status' => 'active', 'room_charge' => 100, 'payment_method' => 'cash']);
    }

    private function item(): PosItem
    {
        return PosItem::create(['name' => 'Posting Test Drink', 'category' => 'Refreshments', 'price' => 100, 'stock_quantity' => 10, 'is_tracked' => true, 'is_available' => true]);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function operation(array $values): array
    {
        return ['operation_key' => (string) Str::uuid()] + $values;
    }
}
