<?php

namespace Tests\Feature;

use App\Models\AccountingExport;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\AccountingExportService;
use App\Services\BusinessDayService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $this->owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $this->actingAs($this->cashier);
    }

    public function test_invoice_stays_disabled_until_approved_setup_is_complete(): void
    {
        $order = $this->order(112);
        $this->post(route('invoices.order', $order), $this->key())->assertSessionHasErrors('configuration');
        $this->assertDatabaseCount('invoices', 0);
        $settings = $this->configuration();
        $settings['tin'] = '';
        $this->actingAs($this->owner)->post(route('admin.finance.setup.update'), $settings)->assertSessionHasErrors('configuration');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_only_owner_can_enable_setup_and_issued_invoices_preserve_tax_number_and_prices(): void
    {
        $settings = $this->configuration();
        $this->actingAs($this->admin)->post(route('admin.finance.setup.update'), $settings)->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.finance.setup.update'), $settings)->assertRedirect()->assertSessionHasNoErrors();
        $order = $this->order(112);
        $key = $this->key();
        $this->actingAs($this->cashier)->post(route('invoices.order', $order), $key)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('invoices.order', $order), $key)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('invoices.order', $order), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('TEST-00000001', $invoice->number);
        $this->assertSame(1200, $invoice->document['tax_cents']);
        $this->assertSame(11200, $invoice->document['net_cents']);
        $this->assertSame(112.0, (float) $order->fresh()->total);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(2, app(BusinessDayService::class)->settings()['next_sequence']);
        $settings['next_sequence'] = 1;
        $this->actingAs($this->owner)->post(route('admin.finance.setup.update'), $settings)->assertSessionHasErrors('next_sequence');
    }

    public function test_invoice_reprint_reuses_original_snapshot_and_counts_requests_once(): void
    {
        $this->configure();
        $order = $this->order(112);
        $this->post(route('invoices.order', $order), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $key = $this->key();
        $this->post(route('invoices.print', $invoice), $key)->assertOk()->assertSee('Recorded print request #1');
        $this->post(route('invoices.print', $invoice), $key)->assertOk()->assertSee('Recorded print request #1');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('TEST-00000001');
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'invoice.print_requested')->count());
        $this->expectException(\LogicException::class);
        $invoice->update(['number' => 'CHANGED']);
    }

    public function test_accounting_setup_preserves_issued_numbering_when_optional_fields_are_omitted(): void
    {
        $this->configure();
        $order = $this->order(112);
        $this->post(route('invoices.order', $order), $this->key())->assertSessionHasNoErrors();
        $settings = $this->configuration();
        unset($settings['invoice_series'], $settings['next_sequence']);

        $this->actingAs($this->owner)->post(route('admin.finance.setup.update'), $settings)->assertRedirect()->assertSessionHasNoErrors();

        $saved = app(BusinessDayService::class)->settings();
        $this->assertSame('TEST', $saved['invoice_series']);
        $this->assertSame(2, $saved['next_sequence']);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_preserved_invoice_counter_cannot_be_rewound_after_history_is_cleared(): void
    {
        $this->configure();
        $order = $this->order(112);
        $this->post(route('invoices.order', $order), $this->key())->assertSessionHasNoErrors();
        DB::table('invoices')->delete();
        $this->assertSame(2, app(BusinessDayService::class)->settings()['next_sequence']);

        $this->actingAs($this->owner)->post(route('admin.finance.setup.update'), $this->configuration())
            ->assertSessionHasErrors('next_sequence');

        $this->assertSame(2, app(BusinessDayService::class)->settings()['next_sequence']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_closed_period_blocks_new_invoice_issuance(): void
    {
        $this->configure();
        $order = $this->order(112);
        BusinessDay::factory()->create(['business_date' => app(BusinessDayService::class)->date(), 'status' => 'closed']);
        $this->post(route('invoices.order', $order), $this->key())->assertSessionHasErrors('business_date');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_cancelled_stays_cannot_issue_invoices_or_consume_an_invoice_number(): void
    {
        $this->configure();
        $room = Room::where('is_staff_quarters', false)->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Cancelled guest'])->id,
            'user_id' => $this->cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'status' => 'voided',
            'rate_tier' => '3h', 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'checked_out_at' => now()]);

        $this->post(route('invoices.folio', $folio), $this->key())->assertConflict();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, app(BusinessDayService::class)->settings()['next_sequence']);
    }

    public function test_cashier_cannot_issue_view_or_print_another_operators_walk_in_invoice(): void
    {
        $this->configure();
        $order = $this->order(112);
        $other = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $this->actingAs($other)->post(route('invoices.order', $order), $this->key())->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
        $this->actingAs($this->cashier)->post(route('invoices.order', $order), $this->key())->assertSessionHasNoErrors();
        $invoice = Invoice::sole();

        $this->actingAs($other)->get(route('invoices.show', $invoice))->assertForbidden();
        $this->post(route('invoices.print', $invoice), $this->key())->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'invoice.print_requested']);
        $this->actingAs($this->owner)->get(route('invoices.show', $invoice))->assertOk();
        $this->actingAs($this->admin)->post(route('invoices.print', $invoice), $this->key())->assertOk();
    }

    public function test_export_requires_closed_day_mappings_and_independent_review_and_retries_are_stable(): void
    {
        $this->configure(false);
        $day = $this->closeDayWithOrder(100);
        $this->actingAs($this->admin)->post(route('admin.finance.export', $day))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.export', $day))->assertRedirect()->assertSessionHasNoErrors();
        $batch = AccountingExport::firstOrFail();
        $this->assertDatabaseCount('accounting_exports', 1);
        $this->assertSame(10000, array_sum(array_column($batch->lines, 'debit_cents')));
        $this->assertSame(10000, array_sum(array_column($batch->lines, 'credit_cents')));
        $this->get(route('admin.finance.exports.download', $batch))->assertConflict();
        $this->post(route('admin.finance.exports.review', $batch))->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.finance.exports.review', $batch))->assertRedirect()->assertSessionHasNoErrors();
        $first = $this->get(route('admin.finance.exports.download', $batch))->assertOk()->getContent();
        $second = $this->get(route('admin.finance.exports.download', $batch))->assertOk()->getContent();
        $this->assertSame($first, $second);
        $this->assertStringContainsString('payment:', $first);
        $this->post(route('admin.finance.exports.delivered', $batch), ['reference' => 'Accountant manual import TEST-1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('delivered', $batch->fresh()->status);
    }

    public function test_reopened_period_supersedes_old_export_and_delivered_batch_gets_linked_correction(): void
    {
        $this->configure(false);
        $day = $this->closeDayWithOrder(100);
        $this->actingAs($this->admin)->post(route('admin.finance.export', $day))->assertRedirect()->assertSessionHasNoErrors();
        $batch = AccountingExport::firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.finance.exports.review', $batch))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.exports.delivered', $batch), ['reference' => 'Original manual delivery'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.reopen', $day), ['reason' => 'Correct accountant snapshot'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.finance.exports.download', $batch))->assertConflict();
        $this->post(route('admin.finance.close'), ['business_date' => $day->business_date->toDateString(), 'reason' => 'Replacement close'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.finance.export', $day))->assertRedirect()->assertSessionHasNoErrors();
        $correction = AccountingExport::latest('id')->firstOrFail();
        $this->assertSame($batch->id, $correction->correction_of_id);
        $this->assertSame(2, $correction->version);
        $this->assertSame(4, count($correction->lines));
        $this->assertSame(20000, array_sum(array_column($correction->lines, 'debit_cents')));
        $this->assertSame(20000, array_sum(array_column($correction->lines, 'credit_cents')));
        $this->assertDatabaseCount('business_day_snapshots', 2);
    }

    public function test_deposit_application_is_liability_transfer_and_export_receivables_balance(): void
    {
        $this->configure(false);
        $date = app(BusinessDayService::class)->date();
        $room = Room::where('is_staff_quarters', false)->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Journal guest'])->id, 'user_id' => $this->cashier->id, 'transaction_id' => 'JOURNAL-1', 'rate_tier' => 'promo', 'room_charge' => 400, 'gross_total' => 400, 'discount_amount' => 40, 'net_total' => 260, 'security_deposit' => 100, 'deposit_status' => 'applied_to_bill', 'expected_checkout_at' => now()->addHours(11), 'checked_in_at' => now()->subHour(), 'checked_out_at' => now(), 'status' => 'checked_out']);
        foreach (['deposit' => 100, 'settlement' => 260] as $kind => $amount) {
            PaymentEntry::create(['folio_id' => $folio->id, 'user_id' => $this->cashier->id, 'kind' => $kind, 'cash_amount' => $amount, 'gcash_amount' => 0, 'posted_at' => now(), 'business_date' => $date]);
        }
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $this->actingAs($this->admin)->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Deposit applied'])->assertRedirect()->assertSessionHasNoErrors();
        $day = BusinessDay::whereDate('business_date', $date)->firstOrFail();
        $this->post(route('admin.finance.export', $day))->assertRedirect()->assertSessionHasNoErrors();
        $lines = collect(AccountingExport::firstOrFail()->lines);
        $receivable = $lines->where('account', 'TEST-guest_receivable');
        $deposit = $lines->where('account', 'TEST-deposits');
        $this->assertSame($receivable->sum('debit_cents'), $receivable->sum('credit_cents'));
        $this->assertSame($deposit->sum('debit_cents'), $deposit->sum('credit_cents'));
        $this->assertSame(0, $day->snapshot['closing_deposit_cents']);
    }

    public function test_finance_and_setup_pages_render_for_reviewer_and_owner(): void
    {
        $this->actingAs($this->admin)->get(route('admin.finance.index'))->assertOk()->assertSee('6:00 AM');
        $this->get(route('admin.finance.setup'))->assertOk()->assertSee('Accounting Setup');
        $this->get(route('approvals.index'))->assertOk()->assertSee('Property review queue');
        $this->actingAs($this->owner)->get(route('admin.finance.setup'))->assertOk();
    }

    public function test_statutory_invoice_uses_independently_approved_taxable_basis(): void
    {
        $this->configure();
        $room = Room::where('is_staff_quarters', false)->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Statutory test guest'])->id, 'user_id' => $this->cashier->id, 'transaction_id' => 'STATUTORY-TEST', 'rate_tier' => 'promo', 'room_charge' => 400, 'gross_total' => 400, 'discount_type' => 'SENIOR', 'discount_amount' => 79, 'net_total' => 321, 'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(11), 'checked_out_at' => now(), 'status' => 'checked_out']);
        PaymentEntry::create(['folio_id' => $folio->id, 'user_id' => $this->cashier->id, 'kind' => 'settlement', 'cash_amount' => 321, 'gcash_amount' => 0, 'posted_at' => now()]);
        $this->post(route('invoices.folio', $folio), $this->key())->assertSessionHasErrors('configuration');
        $this->post(route('approvals.store'), $this->key() + ['kind' => 'invoice_tax_treatment', 'source_id' => $folio->id, 'payload' => ['taxable_amount' => 112], 'reason' => 'TEST mixed taxable and exempt basis', 'evidence_reference' => 'TEST accountant reference'])->assertRedirect()->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $this->post(route('invoices.folio', $folio), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $document = Invoice::firstOrFail()->document;
        $this->assertSame(11200, $document['taxable_cents']);
        $this->assertSame(20900, $document['exempt_cents']);
        $this->assertSame(1200, $document['tax_cents']);
        $this->assertNotNull($document['tax_approval_id']);
        $this->assertSame('321.00', $folio->fresh()->net_total);
    }

    public function test_partial_sale_refund_reverses_its_share_of_invoice_tax_in_export(): void
    {
        config(['sedona.shift_handover_enabled' => true, 'sedona.continuous_shift_enabled' => false]);
        $this->configure();
        $date = app(BusinessDayService::class)->date();
        $shift = Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => now()->subHour(), 'shift_type' => 'day', 'shift_date' => today(), 'opening_float' => 0]);
        $order = $this->order(112);
        $original = PaymentEntry::firstOrFail();
        $this->post(route('invoices.order', $order), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.store'), $this->key() + ['kind' => 'pos_refund', 'source_id' => $original->id, 'payload' => ['amount' => 56, 'payment_method' => 'cash'], 'reason' => 'Half of sale returned'])->assertRedirect()->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $this->post(route('shifts.close', $shift), ['denominations' => ['coins' => 56]])->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $this->actingAs($incoming)->post(route('shifts.accept', $shift), $this->key() + ['accepted_cash' => 56, 'notes' => 'Incoming count'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'VAT sale and refund reviewed'])->assertRedirect()->assertSessionHasNoErrors();
        $day = BusinessDay::whereDate('business_date', $date)->firstOrFail();
        $this->post(route('admin.finance.export', $day))->assertRedirect()->assertSessionHasNoErrors();
        $tax = collect(AccountingExport::firstOrFail()->lines)->where('account', 'TEST-vat_payable');
        $this->assertSame(600, $tax->sum('credit_cents') - $tax->sum('debit_cents'));
        $this->assertSame(600, $day->snapshot['payments'][1]['refund_tax_cents']);
    }

    private function order(float $amount): Order
    {
        $order = Order::create(['transaction_id' => (string) Str::uuid(), 'type' => 'walkin_pos', 'total' => $amount, 'status' => 'delivered', 'user_id' => $this->cashier->id, 'payment_method' => 'cash', 'cash_tendered' => $amount]);
        PaymentEntry::create(['order_id' => $order->id, 'user_id' => $this->cashier->id, 'kind' => 'pos_sale', 'cash_amount' => $amount, 'gcash_amount' => 0, 'posted_at' => now(), 'business_date' => app(BusinessDayService::class)->date()]);

        return $order;
    }

    private function closeDayWithOrder(float $amount): BusinessDay
    {
        $date = app(BusinessDayService::class)->date();
        $this->order($amount);
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $this->actingAs($this->admin)->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Reconciled cash day'])->assertRedirect()->assertSessionHasNoErrors();

        return BusinessDay::whereDate('business_date', $date)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return ['legal_name' => 'TEST ISSUER', 'address' => 'Test address', 'tin' => 'TEST-TIN', 'registration' => 'TEST ONLY', 'approval_reference' => 'TEST accountant fixture', 'tax_mode' => 'vat_inclusive', 'tax_rate_bps' => 1200, 'invoice_series' => 'TEST', 'next_sequence' => 1, 'invoice_enabled' => true, 'export_enabled' => true, 'destination' => 'Test CSV', 'opening_balance_reference' => 'TEST balances', 'account_mappings' => array_combine(AccountingExportService::ACCOUNTS, array_map(fn (string $key): string => 'TEST-'.$key, AccountingExportService::ACCOUNTS))];
    }

    private function configure(bool $invoices = true): void
    {
        $settings = $this->configuration();
        if (! $invoices) {
            $settings['invoice_enabled'] = false;
            $settings['tax_mode'] = 'non_vat';
            $settings['tax_rate_bps'] = 0;
        }
        $this->actingAs($this->owner)->post(route('admin.finance.setup.update'), $settings)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->cashier);
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['operation_key' => (string) Str::uuid()];
    }
}
