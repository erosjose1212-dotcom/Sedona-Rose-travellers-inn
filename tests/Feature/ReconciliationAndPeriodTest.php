<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\BusinessDay;
use App\Models\CashMovement;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\FinancialSummaryService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReconciliationAndPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_cutoff_assigns_early_morning_to_previous_business_day(): void
    {
        $days = app(BusinessDayService::class);
        $this->assertSame('2026-10-07', $days->date(Carbon::parse('2026-10-08 06:29:59', 'Asia/Manila')));
        $this->assertSame('2026-10-08', $days->date(Carbon::parse('2026-10-08 06:30:00', 'Asia/Manila')));
        [$start, $end] = $days->bounds('2026-10-08');
        $this->assertSame('06:30:00', $start->setTimezone('Asia/Manila')->format('H:i:s'));
        $this->assertSame('2026-10-09', $end->toDateString());
    }

    public function test_import_deduplicates_normalized_rows_and_rejects_invalid_net_atomically(): void
    {
        $csv = $this->csv('REF-1', 100, 2, 98);
        $this->import($csv)->assertRedirect()->assertSessionHasNoErrors();
        $this->import($csv)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('statement_lines', 1);
        $invalid = $this->csv('REF-2', 50, 1, 49).'REF-3,'.now()->format('Y-m-d H:i:s').",40,1,39.01\n";
        $this->import($invalid)->assertSessionHasErrors('statement');
        $this->assertDatabaseCount('statement_lines', 1);
    }

    public function test_reference_can_match_multiple_payments_only_when_total_is_exact(): void
    {
        $first = $this->payment(60, 'REF-1');
        $second = $this->payment(40, 'REF-1');
        $this->import($this->csv('REF-1', 100, 2, 98))->assertRedirect()->assertSessionHasNoErrors();
        $line = StatementLine::firstOrFail();
        $this->post(route('admin.finance.match', $line), $this->key(['entries' => (string) $first->id]))->assertSessionHasErrors('entries');
        $this->assertDatabaseCount('statement_matches', 0);
        $data = $this->key(['entries' => $first->id.','.$second->id]);
        $this->post(route('admin.finance.match', $line), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.match', $line), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('statement_matches', 2);
        $this->assertSame(100.0, (float) DB::table('statement_matches')->sum('amount'));
    }

    public function test_wrong_reference_or_self_posted_payment_cannot_be_matched(): void
    {
        $first = $this->payment(100, 'OTHER');
        $this->import($this->csv('REF-1', 100, 0, 100))->assertRedirect()->assertSessionHasNoErrors();
        $line = StatementLine::firstOrFail();
        $this->post(route('admin.finance.match', $line), $this->key(['entries' => (string) $first->id]))->assertSessionHasErrors('entries');
        $own = $this->payment(100, 'REF-1', $this->admin);
        $this->post(route('admin.finance.match', $line), $this->key(['entries' => (string) $own->id]))->assertForbidden();
        $this->assertDatabaseCount('statement_matches', 0);
    }

    public function test_close_blocks_unmatched_provider_movements_and_future_cutoff(): void
    {
        $date = app(BusinessDayService::class)->date();
        $this->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Too early'])->assertSessionHasErrors('business_date');
        $this->payment(100, 'UNMATCHED');
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $this->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Pending provider'])->assertSessionHasErrors('business_date');
        $this->assertDatabaseCount('business_day_snapshots', 0);
    }

    public function test_matching_allows_close_and_owner_reopen_retains_previous_snapshot(): void
    {
        $date = app(BusinessDayService::class)->date();
        $payment = $this->payment(100, 'MATCH-1');
        $this->import($this->csv('MATCH-1', 100, 1, 99))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.match', StatementLine::firstOrFail()), $this->key(['entries' => (string) $payment->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $this->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Provider reconciled'])->assertRedirect()->assertSessionHasNoErrors();
        $day = BusinessDay::whereDate('business_date', $date)->firstOrFail();
        $this->assertSame('closed', $day->status);
        $this->assertSame(10000, $day->snapshot['gcash_cents']);
        $this->post(route('admin.finance.reopen', $day), ['reason' => 'Admin cannot reopen'])->assertForbidden();
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $this->actingAs($owner)->post(route('admin.finance.reopen', $day), ['reason' => 'Accountant requested review'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Replacement review'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $day->fresh()->version);
        $this->assertDatabaseCount('business_day_snapshots', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'business_day.reopened', 'user_id' => $owner->id]);
    }

    public function test_closed_day_blocks_late_expense_and_statement_import(): void
    {
        $date = today()->subDay()->toDateString();
        BusinessDay::factory()->create(['business_date' => $date, 'status' => 'closed']);
        $this->post(route('admin.accounting.expenses.store'), $this->key(['expense_date' => $date, 'category' => 'other', 'description' => 'Late payment', 'amount' => 100, 'payment_source' => 'petty_cash']))->assertSessionHasErrors('business_date');
        $csv = "reference,posted_at,gross_amount,fee_amount,net_amount\nLATE,".$date." 12:00:00,100,0,100\n";
        $this->import($csv)->assertSessionHasErrors('business_date');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('statement_lines', 0);
    }

    public function test_active_stay_crosses_cutoff_without_checkout_and_keeps_full_deposit_liability(): void
    {
        $date = app(BusinessDayService::class)->date();
        $room = Room::where('is_staff_quarters', false)->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Overnight guest'])->id, 'user_id' => $this->cashier->id, 'transaction_id' => 'OVERNIGHT', 'rate_tier' => 'promo', 'room_charge' => 400, 'security_deposit' => 100, 'deposit_status' => 'applied_to_bill', 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(12), 'status' => 'active']);
        PaymentEntry::create(['folio_id' => $folio->id, 'kind' => 'deposit', 'cash_amount' => 100, 'gcash_amount' => 0, 'user_id' => $this->cashier->id, 'posted_at' => now()]);
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $this->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Overnight guest remains'])->assertRedirect()->assertSessionHasNoErrors();
        $day = BusinessDay::whereDate('business_date', $date)->firstOrFail();
        $this->assertSame(0, $day->snapshot['opening_deposit_cents']);
        $this->assertSame(10000, $day->snapshot['closing_deposit_cents']);
        $this->assertTrue($folio->fresh()->isActive());
    }

    public function test_cashier_has_no_property_reconciliation_access(): void
    {
        $this->actingAs($this->cashier)->get(route('admin.finance.index'))->assertForbidden();
        $this->get(route('admin.finance.setup'))->assertForbidden();
        $this->get(route('approvals.index'))->assertOk();
    }

    public function test_trial_reset_is_disabled_without_removing_history(): void
    {
        $this->payment(100, 'KEEP');
        $this->artisan('pms:clean-trial')->assertExitCode(1);
        $this->assertDatabaseCount('payment_entries', 1);
    }

    public function test_incorrect_unmatched_import_can_be_excluded_only_with_independent_review(): void
    {
        $this->import($this->csv('WRONG', 100, 0, 100))->assertRedirect()->assertSessionHasNoErrors();
        $line = StatementLine::firstOrFail();
        $this->post(route('approvals.store'), $this->key(['kind' => 'statement_void', 'source_id' => $line->id, 'reason' => 'Incorrect statement source', 'evidence_reference' => 'TEST merchant correction']))->assertRedirect()->assertSessionHasNoErrors();
        $approval = $this->approveLatestFinancialRequest();
        $this->post(route('approvals.execute', $approval), $this->key([]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('statement_lines', 1);
        $this->assertDatabaseCount('statement_exclusions', 1);
        $this->assertSame([], app(BusinessDayService::class)->snapshot(app(BusinessDayService::class)->date())['exceptions']);
        $this->post(route('admin.finance.match', $line), $this->key(['entries' => '1']))->assertConflict();
    }

    public function test_signed_refund_statement_matching_records_negative_movement(): void
    {
        $sale = $this->payment(100, 'SALE-1');
        $refund = PaymentEntry::create(['order_id' => $sale->order_id, 'user_id' => $this->cashier->id, 'kind' => 'pos_refund', 'cash_amount' => 0, 'gcash_amount' => -30, 'gcash_reference' => 'REFUND-1', 'posted_at' => now(), 'business_date' => app(BusinessDayService::class)->date(), 'reversal_of_id' => $sale->id]);
        $this->import($this->csv('REFUND-1', -30, 0, -30))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.match', StatementLine::firstOrFail()), $this->key(['entries' => (string) $refund->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('statement_matches', ['payment_entry_id' => $refund->id, 'amount' => -30]);
    }

    public function test_bank_deposit_matches_approved_cash_movement_and_recognizes_fee(): void
    {
        $this->actingAs($this->cashier);
        $shift = Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => now()->subHour(), 'shift_type' => 'day', 'shift_date' => today(), 'opening_float' => 100]);
        $this->post(route('approvals.store'), $this->key(['kind' => 'bank_deposit', 'source_id' => $shift->id, 'payload' => ['amount' => 30, 'destination' => 'bank', 'reference' => 'BANK-1'], 'reason' => 'Bank deposit verified']))->assertRedirect()->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $movement = CashMovement::firstOrFail();
        $this->assertSame('-30.00', $movement->amount);
        $this->actingAs($this->admin)->post(route('admin.finance.import'), ['provider' => 'bank', 'statement' => UploadedFile::fake()->createWithContent('bank.csv', $this->csv('BANK-1', 30, 1, 29))])->assertRedirect()->assertSessionHasNoErrors();
        $line = StatementLine::firstOrFail();
        $this->post(route('admin.finance.match', $line), $this->key(['entries' => (string) $movement->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('statement_matches', ['cash_movement_id' => $movement->id, 'amount' => 30]);
        $this->assertSame('1.00', $line->fee_amount);
        $summary = app(FinancialSummaryService::class)->period(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(1.0, $summary['providerFees']);
        $this->assertSame(-1.0, $summary['profit']);
        $this->assertSame(70.0, app(FinancialSummaryService::class)->shift($shift)['expectedCash']);
    }

    public function test_closed_day_screen_uses_the_preserved_snapshot_instead_of_live_recalculation(): void
    {
        $date = today()->subDay()->toDateString();
        $snapshot = app(BusinessDayService::class)->snapshot($date);
        $snapshot['cash_cents'] = 777;
        BusinessDay::factory()->create(['business_date' => $date, 'status' => 'closed', 'version' => 1, 'snapshot' => $snapshot]);
        $this->get(route('admin.finance.index', ['date' => $date]))->assertOk()->assertViewHas('snapshot', fn (array $stored): bool => $stored['cash_cents'] === 777);
    }

    public function test_day_close_requires_review_of_the_actual_outgoing_variance_instead_of_a_stale_approval(): void
    {
        $date = today()->subDay()->toDateString();
        $shift = Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => today()->subDay()->setTime(12, 0), 'closed_at' => today()->subDay()->setTime(22, 0), 'shift_type' => 'day', 'shift_date' => $date, 'opening_float' => 100, 'cash_total' => 90, 'expected_cash' => 100, 'cash_variance' => -10, 'handover_status' => 'accepted']);
        ApprovalRequest::factory()->create(['kind' => 'shift_variance', 'source_type' => Shift::class, 'source_id' => $shift->id, 'requested_by' => $this->cashier->id, 'reviewed_by' => $this->admin->id, 'executed_by' => $this->cashier->id, 'status' => 'executed', 'payload' => [], 'source_hash' => str_repeat('0', 64)]);

        $this->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Count reviewed'])->assertSessionHasErrors('business_date');

        $this->assertDatabaseMissing('business_days', ['business_date' => $date, 'status' => 'closed']);
        $this->actingAs($this->cashier)->post(route('approvals.store'), $this->key(['kind' => 'shift_variance', 'source_id' => $shift->id, 'reason' => 'Review actual ten peso shortage']))->assertRedirect()->assertSessionHasNoErrors();
        $this->executeLatestFinancialRequest();
        $this->actingAs($this->admin)->post(route('admin.finance.close'), ['business_date' => $date, 'reason' => 'Actual count independently reviewed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_days', ['business_date' => $date, 'status' => 'closed']);
    }

    private function payment(float $amount, string $reference, ?User $actor = null): PaymentEntry
    {
        $order = Order::create(['transaction_id' => (string) Str::uuid(), 'type' => 'walkin_pos', 'user_id' => ($actor ?? $this->cashier)->id, 'total' => $amount, 'payment_method' => 'gcash', 'gcash_amount' => $amount, 'gcash_reference' => $reference, 'status' => 'delivered']);

        return PaymentEntry::create(['order_id' => $order->id, 'user_id' => ($actor ?? $this->cashier)->id, 'kind' => 'pos_sale', 'cash_amount' => 0, 'gcash_amount' => $amount, 'gcash_reference' => $reference, 'posted_at' => now(), 'business_date' => app(BusinessDayService::class)->date()]);
    }

    private function csv(string $reference, float $gross, float $fee, float $net): string
    {
        return "reference,posted_at,gross_amount,fee_amount,net_amount\n".$reference.','.now()->format('Y-m-d H:i:s').','.$gross.','.$fee.','.$net."\n";
    }

    private function import(string $csv): TestResponse
    {
        return $this->post(route('admin.finance.import'), ['provider' => 'gcash', 'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv)]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function key(array $data): array
    {
        return $data + ['operation_key' => (string) Str::uuid()];
    }
}
