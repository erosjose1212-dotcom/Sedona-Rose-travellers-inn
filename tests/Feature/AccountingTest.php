<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\StatementLine;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_and_owner_can_access_pnl_statement()
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->first();
        $owner = User::where('email', 'owner@sedonapms.com')->first();

        $responseAdmin = $this->actingAs($admin)->get(route('admin.accounting.pnl'));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertSee('OPERATING REVENUE STREAMS');
        $responseAdmin->assertSee('OPERATING EXPENSES (OPEX)', false);
        $responseAdmin->assertSee('NET OPERATING PROFIT');

        $responseOwner = $this->actingAs($owner)->get(route('admin.accounting.pnl'));
        $responseOwner->assertStatus(200);
    }

    public function test_retried_expense_confirmation_keeps_the_saved_voucher_number(): void
    {
        $this->travelTo('2026-10-14 12:00:00');
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $data = ['operation_key' => (string) Str::uuid(), 'expense_date' => '2026-10-14',
            'category' => 'supplies', 'description' => 'Retry confirmation test', 'amount' => 100,
            'payment_source' => 'petty_cash', 'receipt_reference' => 'QA-EXPENSE-RETRY'];
        $this->actingAs($admin)->post(route('admin.accounting.expenses.store'), $data)->assertSessionHasNoErrors();
        $expense = Expense::where('receipt_reference', $data['receipt_reference'])->firstOrFail();

        $this->post(route('admin.accounting.expenses.store'), $data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, $expense->voucher_number));

        $this->assertSame(1, Expense::where('receipt_reference', $data['receipt_reference'])->count());
        $this->assertSame('100.00', $expense->fresh()->amount);
    }

    public function test_operating_expense_lifecycle()
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->first();

        // View expenses ledger
        $response = $this->actingAs($admin)->get(route('admin.accounting.expenses'));
        $response->assertStatus(200);
        $response->assertSee('OPERATING EXPENSE VOUCHER JOURNAL');

        // Store new expense
        $storeResponse = $this->actingAs($admin)->post(route('admin.accounting.expenses.store'), ['operation_key' => (string) Str::uuid(),
            'expense_date' => now()->toDateString(),
            'category' => 'utilities',
            'description' => 'High-speed Fiber Internet Monthly Subscription',
            'amount' => 2999.00,
            'payment_source' => 'petty_cash',
            'receipt_reference' => 'PLDT-99120',
        ]);

        $storeResponse->assertRedirect(route('admin.accounting.expenses'));
        $this->assertDatabaseHas('expenses', [
            'description' => 'High-speed Fiber Internet Monthly Subscription',
            'amount' => 2999.00,
            'payment_source' => 'petty_cash',
            'receipt_reference' => 'PLDT-99120',
        ]);

        $expense = Expense::where('receipt_reference', 'PLDT-99120')->first();
        $this->assertNotNull($expense);

        $this->executeLatestFinancialRequest();
        // Request a recorded cash-return reversal
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.accounting.expenses.delete', $expense->id), ['operation_key' => (string) Str::uuid(), 'reason' => 'Supplier returned funds', 'evidence_reference' => 'RETURN-TEST']);
        $deleteResponse->assertRedirect(route('approvals.index'));
        $this->executeLatestFinancialRequest();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 2999]);
        $this->assertDatabaseHas('expenses', ['reversal_of_id' => $expense->id, 'amount' => -2999]);
    }

    public function test_all_printable_documents_generate_cleanly()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::where('number', '1')->first();

        // Check in guest to generate active folio
        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Printable Test Guest',
            'rate_tier' => '12h',
            'headcount' => 2,
        ]);

        $folio = Folio::where('room_id', $room->id)->where('status', 'active')->first();
        $this->assertNotNull($folio);
        $folio->update(['security_deposit' => 500.00]);

        // 1. Draft bill before settlement
        $receiptResp = $this->actingAs($cashier)->get(route('folios.receipt', $folio->id));
        $receiptResp->assertStatus(200);
        $receiptResp->assertSee('DRAFT BILL - PAYMENT PENDING');
        $receiptResp->assertSee('Not issued')->assertDontSee($folio->transaction_id);

        // 2. Security Deposit Slip
        $depositResp = $this->actingAs($cashier)->get(route('folios.deposit_slip', $folio->id));
        $depositResp->assertStatus(200);
        $depositResp->assertSee('SECURITY DEPOSIT RECEIPT');
        $depositResp->assertSee('₱500.00');

        // 3. Statement of Account / Billing Statement
        $billingResp = $this->actingAs($cashier)->get(route('folios.billing', $folio->id));
        $billingResp->assertStatus(200);
        $billingResp->assertSee('STATEMENT OF ACCOUNT / GUEST FOLIO');
        $billingResp->assertSee('Room Base');

        // 4. Kitchen Order Slip
        $orderSlipResp = $this->actingAs($cashier)->get(route('folios.orderslip', $folio->id));
        $orderSlipResp->assertStatus(200);
        $orderSlipResp->assertSee('ORDER SLIP');

        // 5. Loss Slip
        $lossSlipResp = $this->actingAs($cashier)->get(route('folios.loss_slip', $folio->id));
        $lossSlipResp->assertStatus(409);
        $this->post(route('folios.force_checkout', $folio), ['operation_key' => (string) Str::uuid(), 'force_reason' => 'Guest left without payment'])->assertRedirect();
        $this->executeLatestFinancialRequest();
        $this->get(route('folios.loss_slip', $folio))->assertOk()->assertSee('FORCE CHECKOUT WRITE-OFF LOSS SLIP');
    }

    public function test_csv_export_streams_properly()
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->first();

        $response = $this->actingAs($admin)->get(route('admin.accounting.pnl.csv', ['range' => 'this_month']));
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }

    public function test_pnl_and_csv_itemize_returns_other_expenses_and_matched_fees_before_net_totals(): void
    {
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $order = Order::create(['transaction_id' => 'REPORT-SALE', 'type' => 'walkin_pos', 'user_id' => $cashier->id, 'total' => 100, 'payment_method' => 'gcash', 'gcash_amount' => 100, 'gcash_reference' => 'REPORT-GCASH', 'status' => 'delivered']);
        $sale = PaymentEntry::create(['order_id' => $order->id, 'kind' => 'pos_sale', 'cash_amount' => 0, 'gcash_amount' => 100, 'gcash_reference' => 'REPORT-GCASH', 'user_id' => $cashier->id, 'posted_at' => now()]);
        PaymentEntry::create(['order_id' => $order->id, 'kind' => 'pos_refund', 'cash_amount' => 0, 'gcash_amount' => -30, 'gcash_reference' => 'REPORT-REFUND', 'reversal_of_id' => $sale->id, 'user_id' => $cashier->id, 'posted_at' => now()]);
        Expense::create(['user_id' => $cashier->id, 'voucher_number' => 'REPORT-EXPENSE', 'expense_date' => today(), 'category' => 'other', 'description' => 'Sundry expense', 'amount' => 5, 'payment_source' => 'petty_cash', 'status' => 'approved']);
        $statement = StatementLine::factory()->create(['reference' => 'REPORT-GCASH', 'user_id' => $admin->id]);
        DB::table('statement_matches')->insert(['statement_line_id' => $statement->id, 'payment_entry_id' => $sale->id, 'amount' => 100, 'user_id' => $admin->id, 'created_at' => now()]);

        $this->actingAs($admin)->get(route('admin.accounting.pnl', ['range' => 'today']))
            ->assertOk()->assertViewHas('salesReturns', 30.0)
            ->assertViewHas('netRevenueCollected', 70.0)->assertViewHas('totalOpex', 7.0)->assertViewHas('netOperatingProfit', 63.0)
            ->assertSeeInOrder(['Sales Returns', 'NET SETTLED REVENUE'])
            ->assertSee('Other Sundry Expenses')->assertSee('Matched Provider / Bank Fees');
        $csv = $this->get(route('admin.accounting.pnl.csv', ['range' => 'today']))->assertOk()->streamedContent();

        $this->assertStringContainsString('"Less: Sales Returns",30.00', $csv);
        $this->assertStringContainsString('"NET SETTLED REVENUE",70.00', $csv);
        $this->assertLessThan(strpos($csv, 'NET SETTLED REVENUE'), strpos($csv, 'Less: Sales Returns'));
        $this->assertStringContainsString('Other,5.00', $csv);
        $this->assertStringContainsString('"Matched Provider / Bank Fees",2.00', $csv);
        $this->assertStringContainsString('"NET OPERATING PROFIT (EBITDA)",63.00', $csv);
    }
}
