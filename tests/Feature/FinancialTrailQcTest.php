<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\BillingService;
use App\Services\BusinessDayService;
use App\Services\FinancialSummaryService;
use App\Services\ManagementReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialTrailQcTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'sedona.continuous_shift_enabled' => true, 'sedona.shift_handover_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
        $this->cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $this->shift = Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => now()->subHours(6), 'shift_type' => 'day', 'shift_date' => today(), 'opening_float' => 100]);
    }

    public function test_returning_an_expense_before_its_review_does_not_leave_a_false_unresolved_cash_loss(): void
    {
        $this->actingAs($this->cashier)->post(route('shifts.expense.store'), $this->key() + ['description' => 'Unused supplies', 'amount' => 20, 'category' => 'other'])->assertSessionHasNoErrors();
        $expense = Expense::sole();
        $review = ApprovalRequest::where('kind', 'expense_review')->sole();
        $this->actingAs($this->admin)->delete(route('admin.accounting.expenses.delete', $expense), $this->key() + ['reason' => 'Supplier returned cash', 'evidence_reference' => 'RETURN-1'])->assertSessionHasNoErrors();
        $reversal = ApprovalRequest::where('kind', 'expense_reversal')->sole();
        $other = User::where('email', 'admin2@sedonapms.com')->firstOrFail();
        $this->actingAs($other)->post(route('approvals.review', $reversal), ['decision' => 'approve', 'note' => 'Return witnessed'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('approvals.execute', $reversal), $this->key() + ['shift_override_reason' => 'Record returned cash'])->assertSessionHasNoErrors();
        $this->post(route('approvals.review', $review), ['decision' => 'reject', 'note' => 'Purchase was returned'])->assertSessionHasNoErrors();

        $this->assertSame(100.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
        $this->assertSame('rejected', $review->fresh()->status);
        $this->assertSame('approved', $expense->fresh()->status);
        $this->assertSame(0.0, (float) Expense::sum('amount'));
        $this->travelTo(CarbonImmutable::parse('2026-10-27 07:00', 'Asia/Manila'));
        $request = Request::create('/finance/close', 'POST');
        $request->setUserResolver(fn (): User => $this->admin);
        $closed = app(BusinessDayService::class)->close($request, '2026-10-26', 'Expense return reconciled');
        $this->assertSame('closed', $closed->status);
    }

    public function test_weekly_reports_distinguish_collections_from_discounted_revenue(): void
    {
        $active = $this->stay(['security_deposit' => 500, 'deposit_collected_at' => now()]);
        $this->entry($active, 'deposit', 500);
        $settled = $this->stay(['status' => 'checked_out', 'checked_out_at' => now(), 'room_charge' => 1000, 'gross_total' => 1000, 'discount_amount' => 100, 'net_total' => 900, 'discount_type' => 'DC', 'cash_tendered' => 900]);
        $this->entry($settled, 'settlement', 900);
        $response = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk();
        $this->assertSame(1400.0, $response->viewData('todayCollections'));
        $this->assertSame(900.0, $response->viewData('weekRevenue'));
        $this->assertSame(1000.0, $response->viewData('grossWeeklyRevenue'));
        $this->assertStringContainsString('7-Day Net Revenue', $response->getContent());
        $this->assertStringNotContainsString('20% Statutory', $response->getContent());
    }

    public function test_weekly_itemized_expenses_include_matched_provider_fees(): void
    {
        $order = Order::create(['transaction_id' => 'FEE-SALE', 'type' => 'walkin_pos', 'user_id' => $this->cashier->id, 'total' => 100, 'payment_method' => 'gcash', 'gcash_amount' => 100, 'status' => 'delivered']);
        $entry = PaymentEntry::create(['order_id' => $order->id, 'kind' => 'pos_sale', 'cash_amount' => 0, 'gcash_amount' => 100, 'user_id' => $this->cashier->id, 'posted_at' => now(), 'business_date' => '2026-10-26']);
        $statement = StatementLine::factory()->create(['user_id' => $this->admin->id]);
        DB::table('statement_matches')->insert(['statement_line_id' => $statement->id, 'payment_entry_id' => $entry->id, 'amount' => 100, 'user_id' => $this->admin->id, 'created_at' => now()]);
        $response = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk();
        $this->assertSame(2.0, $response->viewData('totalOperatingExpenses'));
        $this->assertSame(2.0, (float) $response->viewData('standardExpenses')->sum('amount'));
        $this->assertSame(98.0, $response->viewData('netWeeklyOperatingProfit'));
    }

    public function test_export_preserves_the_deposit_funded_part_of_a_write_off_as_revenue(): void
    {
        $folio = $this->stay(['status' => 'checked_out', 'checked_out_at' => now(), 'room_charge' => 1000, 'gross_total' => 1000, 'net_total' => 700, 'security_deposit' => 300, 'deposit_status' => 'applied_to_bill', 'force_checkout' => true, 'force_by' => $this->admin->id]);
        $this->entry($folio, 'deposit', 300);
        $this->entry($folio, 'write_off', 0);
        $service = app(ManagementReportService::class);
        $book = $service->build('ledger', $service->period(['report_date' => '2026-10-26']));
        $row = $book['sheets'][0]['rows'][0];
        $this->assertSame(300.0, (float) $row[11]);
        $this->assertSame(300.0, app(FinancialSummaryService::class)->period(now()->startOfDay(), now())['netRevenue']);
    }

    public function test_new_billing_print_uses_current_time_and_retries_keep_the_saved_snapshot(): void
    {
        $folio = $this->stay();
        $first = $this->key() + ['checked_out_at' => '2026-10-26 09:00:00'];
        $this->actingAs($this->cashier)->post(route('folios.billing.print', $folio), $first)->assertOk()->assertViewHas('excessHours', 1);
        $this->travel(1)->hours();
        $this->post(route('folios.billing.print', $folio), $first)->assertOk()->assertViewHas('excessHours', 1)->assertViewHas('receiptCopy', 1);
        $this->post(route('folios.billing.print', $folio), $this->key() + ['checked_out_at' => '2026-10-26 09:00:00'])->assertOk()->assertViewHas('excessHours', 2)->assertViewHas('receiptCopy', 2);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_settled_extras_do_not_change_when_current_extra_rates_change(): void
    {
        $folio = $this->stay(['status' => 'checked_out', 'checked_out_at' => '2026-10-26 10:00:00', 'extra_persons' => 1, 'surcharge_total' => 150, 'gross_total' => 545, 'net_total' => 545]);
        $before = $folio->fresh()->getAttributes();
        config(['sedona.extra_person_rate' => 200]);
        $bill = app(BillingService::class)->calculate($folio);
        $this->assertSame(150.0, (float) $bill['addOnsTotal']);
        $this->assertSame(150.0, (float) collect($bill['addOnItems'])->sum('amount'));
        $this->assertSame(545.0, (float) $bill['grossTotal']);
        $this->assertSame($before, $folio->fresh()->getAttributes());
    }

    public function test_ledger_does_not_label_an_unpaid_bill_balance_as_collected_money(): void
    {
        $this->stay(['gross_total' => 395, 'net_total' => 395]);
        $response = $this->actingAs($this->admin)->get(route('admin.accounting.ledger'))->assertOk();
        $this->assertFalse(str_contains($response->getContent(), 'NET COLLECTED'), 'Unpaid bill balances must not be called collected money.');
        $this->assertStringContainsString('BILL BALANCE', $response->getContent());
        $this->assertDatabaseCount('payment_entries', 0);
    }

    private function stay(array $overrides = []): Folio
    {
        return Folio::create($overrides + ['room_id' => Room::where('number', '6')->firstOrFail()->id, 'guest_id' => Guest::create(['name' => 'Finance QA Guest', 'headcount' => 2])->id, 'user_id' => $this->cashier->id, 'transaction_id' => 'QA-'.Str::uuid(), 'rate_tier' => '3h', 'stay_mode' => 'fixed', 'status' => 'active', 'room_charge' => 395, 'checked_in_at' => '2026-10-26 08:00:00', 'expected_checkout_at' => '2026-10-26 11:00:00']);
    }

    private function entry(Folio $folio, string $kind, float $cash): PaymentEntry
    {
        return PaymentEntry::create(['folio_id' => $folio->id, 'shift_id' => $this->shift->id, 'user_id' => $this->cashier->id, 'kind' => $kind, 'cash_amount' => $cash, 'gcash_amount' => 0, 'posted_at' => now(), 'business_date' => '2026-10-26']);
    }

    private function key(): array
    {
        return ['operation_key' => (string) Str::uuid()];
    }
}
