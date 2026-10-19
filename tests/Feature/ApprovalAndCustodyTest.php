<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\BusinessDay;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\FinancialSummaryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ApprovalAndCustodyTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $reviewer;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->reviewer = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $this->actingAs($this->cashier);
        $this->shift = Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => now()->subHour(), 'shift_type' => 'day', 'shift_date' => today(), 'opening_float' => 100]);
    }

    public function test_review_is_independent_and_approved_execution_is_once_only(): void
    {
        $this->actingAs($this->reviewer);
        $approval = $this->requestApproval('cash_drop', $this->shift->id, ['amount' => 20, 'destination' => 'safe', 'reference' => 'DROP-1']);
        $this->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Self review'])->assertForbidden();
        $other = User::where('email', 'admin2@sedonapms.com')->firstOrFail();
        $this->actingAs($other)->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Verified amount'])->assertRedirect()->assertSessionHasNoErrors();
        $data = $this->key(['shift_override_reason' => 'Cover cashier for safe drop']);
        $this->actingAs($this->reviewer)->post(route('approvals.execute', $approval), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $approval), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $approval), $this->key(['shift_override_reason' => 'Cover cashier for safe drop']))->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(80.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
    }

    public function test_stock_changes_apply_immediately_and_retries_do_not_reset_later_counts(): void
    {
        $item = PosItem::where('is_tracked', true)->firstOrFail();
        $before = $item->stock_quantity;
        $data = $this->key(['stock_quantity' => 10, 'reason' => 'restock']);
        $this->post(route('inventory.update-stock', $item), $data)->assertRedirect(route('inventory.index'))->assertSessionHasNoErrors();
        $this->assertSame($before + 10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.restocked', 'auditable_id' => $item->id]);
        $item->update(['stock_quantity' => $before + 9]);
        $this->post(route('inventory.update-stock', $item), $data)->assertSessionHasNoErrors();
        $this->assertSame($before + 9, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
    }

    public function test_immediate_stock_recount_is_atomic_and_has_audited_result(): void
    {
        $this->actingAs($this->reviewer);
        $items = PosItem::where('is_tracked', true)->take(2)->get();
        $counts = [$items[0]->id => 17, $items[1]->id => 23];
        $this->post(route('inventory.recount'), $this->key(['counts' => $counts]))->assertRedirect()->assertSessionHasNoErrors();
        $approval = ApprovalRequest::latest('id')->firstOrFail();
        $this->assertSame('executed', $approval->status);
        $this->assertNull($approval->reviewed_by);
        $this->assertSame(17, $items[0]->fresh()->stock_quantity);
        $this->assertSame(23, $items[1]->fresh()->stock_quantity);
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval.executed', 'auditable_id' => $approval->id]);
    }

    public function test_paid_expense_stays_in_cash_count_while_pending_or_rejected(): void
    {
        $data = $this->key(['description' => 'Cleaning supply paid', 'amount' => 20, 'category' => 'other', 'receipt_reference' => 'SUP-1']);
        $this->post(route('shifts.expense.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shifts.expense.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $expense = Expense::firstOrFail();
        $this->assertSame('pending_review', $expense->status);
        $this->assertSame(80.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
        $approval = ApprovalRequest::firstOrFail();
        $this->actingAs($this->reviewer)->post(route('approvals.review', $approval), ['decision' => 'reject', 'note' => 'Missing evidence'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected_paid', $expense->fresh()->status);
        $this->assertSame(80.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_expense_reversal_retains_original_and_records_returned_cash(): void
    {
        $this->post(route('shifts.expense.store'), $this->key(['description' => 'Unused supply', 'amount' => 20, 'category' => 'other']))->assertRedirect()->assertSessionHasNoErrors();
        $this->approve(ApprovalRequest::firstOrFail());
        $expense = Expense::firstOrFail();
        $this->actingAs($this->reviewer)->delete(route('admin.accounting.expenses.delete', $expense), $this->key(['reason' => 'Supplier returned funds', 'evidence_reference' => 'RETURN-1']))->assertRedirect()->assertSessionHasNoErrors();
        $approval = ApprovalRequest::where('kind', 'expense_reversal')->firstOrFail();
        $this->actingAs(User::where('email', 'admin2@sedonapms.com')->firstOrFail())->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Funds witnessed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reviewer)->post(route('approvals.execute', $approval), $this->key(['shift_override_reason' => 'Recording returned funds']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 20]);
        $this->assertDatabaseHas('expenses', ['reversal_of_id' => $expense->id, 'amount' => -20, 'approval_request_id' => $approval->id]);
        $this->assertSame(100.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
    }

    public function test_a_paid_expense_cannot_receive_a_duplicate_pending_review(): void
    {
        $this->post(route('shifts.expense.store'), $this->key(['description' => 'Cleaning supplies', 'amount' => 20, 'category' => 'other']))->assertRedirect()->assertSessionHasNoErrors();
        $expense = Expense::firstOrFail();
        $approval = ApprovalRequest::firstOrFail();

        $this->post(route('approvals.store'), $this->key(['kind' => 'expense_review', 'source_id' => $expense->id, 'reason' => 'Duplicate review']))->assertSessionHasErrors('approval');

        $this->assertDatabaseCount('approval_requests', 1);
        $this->assertSame('pending_review', $expense->fresh()->status);
        $this->approve($approval);
        $this->assertSame('approved', $expense->fresh()->status);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_rejecting_a_historical_duplicate_review_preserves_an_approved_expense(bool $closedPeriod): void
    {
        $this->post(route('shifts.expense.store'), $this->key(['description' => 'Cleaning supplies', 'amount' => 20, 'category' => 'other']))->assertRedirect()->assertSessionHasNoErrors();
        $expense = Expense::firstOrFail();
        $approval = ApprovalRequest::firstOrFail();
        $duplicate = ApprovalRequest::factory()->create($approval->only(['kind', 'source_type', 'source_id', 'requested_by', 'payload', 'source_hash', 'reason']));
        $this->approve($approval);
        if ($closedPeriod) {
            BusinessDay::whereDate('business_date', $expense->expense_date)->firstOrFail()->update(['status' => 'closed']);
        }

        $this->actingAs($this->reviewer)->post(route('approvals.review', $duplicate), ['decision' => 'reject', 'note' => 'Stale duplicate review'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('rejected', $duplicate->fresh()->status);
        $this->assertSame('approved', $expense->fresh()->status);
        $this->assertSame($approval->id, $expense->fresh()->approval_request_id);
        $this->assertSame(80.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
    }

    public function test_outgoing_variance_review_requires_a_closed_drawer_with_a_nonzero_variance(): void
    {
        $data = ['kind' => 'shift_variance', 'source_id' => $this->shift->id, 'reason' => 'Review outgoing count'];

        $this->post(route('approvals.store'), $this->key($data))->assertConflict();
        $this->shift->update(['closed_at' => now(), 'cash_total' => 100, 'expected_cash' => 100, 'cash_variance' => 0]);
        $this->post(route('approvals.store'), $this->key($data))->assertConflict();

        $this->assertDatabaseCount('approval_requests', 0);
        $this->shift->update(['cash_total' => 90, 'cash_variance' => -10]);
        $approval = $this->requestApproval('shift_variance', $this->shift->id, []);
        $this->approve($approval);
        $this->post(route('approvals.execute', $approval), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('executed', $approval->fresh()->status);
    }

    public function test_deposit_refunds_require_review_and_cannot_overpay_with_two_approvals(): void
    {
        $folio = $this->folio();
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now(), 'security_deposit' => 150, 'deposit_status' => 'not_applied']);
        $first = $this->requestApproval('deposit_refund', $folio->id, ['amount' => 100, 'payment_method' => 'cash']);
        $second = $this->requestApproval('deposit_refund', $folio->id, ['amount' => 80, 'payment_method' => 'cash']);
        $this->post(route('approvals.execute', $first), $this->key())->assertSessionHasErrors('approval');
        $this->approve($first);
        $this->approve($second);
        $key = $this->key();
        $this->post(route('approvals.execute', $first), $key)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $first), $key)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $second), $this->key())->assertSessionHasErrors('amount');
        $this->assertSame('100.00', $folio->fresh()->deposit_refunded_amount);
        $this->assertDatabaseCount('payment_entries', 1);
    }

    public function test_sale_refund_links_original_without_automatic_restock(): void
    {
        $item = PosItem::where('is_tracked', true)->firstOrFail();
        $order = Order::create(['transaction_id' => 'TEST-POS', 'type' => 'walkin_pos', 'user_id' => $this->cashier->id, 'payment_method' => 'cash', 'total' => 100, 'status' => 'delivered', 'shift_id' => $this->shift->id]);
        $payment = PaymentEntry::create(['order_id' => $order->id, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id, 'kind' => 'pos_sale', 'cash_amount' => 100, 'gcash_amount' => 0, 'posted_at' => now()]);
        $stock = $item->stock_quantity;
        $approval = $this->requestApproval('pos_refund', $payment->id, ['amount' => 30, 'payment_method' => 'cash']);
        $this->approve($approval);
        $this->post(route('approvals.execute', $approval), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payment_entries', ['kind' => 'pos_refund', 'cash_amount' => -30, 'reversal_of_id' => $payment->id, 'approval_request_id' => $approval->id]);
        $this->assertSame($stock, $item->fresh()->stock_quantity);
        $this->post(route('approvals.store'), $this->key(['kind' => 'pos_refund', 'source_id' => $payment->id, 'payload' => ['amount' => 71, 'payment_method' => 'cash'], 'reason' => 'Excess refund']))->assertSessionHasErrors('amount');
    }

    public function test_rate_time_and_discount_overrides_cannot_bypass_review_through_checkout_drafts(): void
    {
        $folio = $this->folio();
        $this->postJson(route('folios.deposit', $folio), ['room_charge' => 1])->assertUnprocessable();
        $this->postJson(route('folios.deposit', $folio), ['discount_type' => 'dc'])->assertOk();
        $this->postJson(route('folios.deposit', $folio), ['discount_override_amount' => 200])->assertOk();
        $this->assertNull($folio->fresh()->discount_override_amount);
        $this->postJson(route('folios.deposit', $folio), ['discount_type' => 'senior'])->assertUnprocessable();
        $approval = $this->requestApproval('folio_override', $folio->id, ['room_charge' => 300, 'discount_type' => 'DC']);
        $this->approve($approval);
        $this->post(route('approvals.execute', $approval), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('300.00', $folio->fresh()->room_charge);
        $this->assertSame('DC', $folio->fresh()->discount_type);
        $this->postJson(route('folios.deposit', $folio), ['room_charge' => 300, 'discount_type' => 'dc'])->assertOk();
    }

    public function test_write_off_review_does_not_allow_a_cashier_to_approve(): void
    {
        $folio = $this->folio();
        $this->post(route('folios.force_checkout', $folio), $this->key(['force_reason' => 'Guest departed unpaid']))->assertRedirect()->assertSessionHasNoErrors();
        $approval = ApprovalRequest::firstOrFail();
        $this->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Cashier review'])->assertForbidden();
        $this->assertTrue($folio->fresh()->isActive());
        $this->approve($approval);
        $this->post(route('approvals.execute', $approval), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->cashier->id, $folio->fresh()->force_requested_by);
        $this->assertSame($this->reviewer->id, $folio->fresh()->force_by);
        $this->assertSame('checked_out', $folio->fresh()->status);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_admin_corrected_arrival_time_preserves_the_manila_hour_with_or_without_an_explicit_offset(bool $withOffset): void
    {
        $folio = $this->folio();
        $folio->update(['rate_tier' => '12h']);
        $arrival = now()->subHours(2);
        $input = $withOffset ? $arrival->setTimezone('UTC')->toIso8601String() : $arrival->format('Y-m-d H:i:s');
        $this->actingAs($this->reviewer)->post('/admin/check-in-times/'.$folio->id, $this->key() + [
            'checked_in_at' => $input, 'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(), 'reason' => 'RA corrected declaration time.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('10:00:00', $folio->fresh()->checked_in_at->format('H:i:s'));
        $this->assertSame('22:00:00', $folio->fresh()->expected_checkout_at->format('H:i:s'));
    }

    public function test_handover_acceptance_opens_incoming_drawer_once_and_retains_outgoing_count(): void
    {
        config(['sedona.shift_handover_enabled' => true]);
        $this->post(route('shifts.close', $this->shift), ['denominations' => [100 => 1]])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shifts.open'), $this->key(['opening_float' => 100]))->assertSessionHasErrors('shift');
        $this->post(route('shifts.accept', $this->shift), $this->key(['accepted_cash' => 100, 'notes' => 'Own count']))->assertForbidden();
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $data = $this->key(['accepted_cash' => 100, 'notes' => 'Witnessed count']);
        $this->actingAs($incoming)->post(route('shifts.accept', $this->shift), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shifts.accept', $this->shift), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shifts', 2);
        $this->assertDatabaseHas('shifts', ['handover_from_id' => $this->shift->id, 'opened_by' => $incoming->id, 'opening_float' => 100]);
        $this->assertSame('100.00', $this->shift->fresh()->cash_total);
        $this->assertSame($incoming->id, $this->shift->fresh()->accepted_by);
    }

    public function test_disputed_handover_requires_independent_review_of_incoming_count(): void
    {
        config(['sedona.shift_handover_enabled' => true]);
        $this->post(route('shifts.close', $this->shift), ['denominations' => [100 => 1]])->assertRedirect()->assertSessionHasNoErrors();
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $this->actingAs($incoming)->post(route('shifts.accept', $this->shift), $this->key(['accepted_cash' => 90, 'notes' => 'Ten pesos short']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('disputed', $this->shift->fresh()->handover_status);
        $this->assertDatabaseCount('shifts', 1);
        $approval = ApprovalRequest::where('kind', 'handover_variance')->firstOrFail();
        $this->approve($approval, $incoming);
        $this->post(route('approvals.execute', $approval), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shifts.accept', $this->shift), $this->key(['accepted_cash' => 91, 'notes' => 'Changed count']))->assertSessionHasErrors('accepted_cash');
        $this->post(route('shifts.accept', $this->shift), $this->key(['accepted_cash' => 90, 'notes' => 'Reviewed count']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('-10.00', $this->shift->fresh()->handover_variance);
    }

    public function test_stale_approval_can_be_cancelled_but_paid_expense_review_cannot_be_hidden(): void
    {
        $item = PosItem::where('is_tracked', true)->firstOrFail();
        $request = ApprovalRequest::factory()->create(['source_id' => $item->id, 'requested_by' => $this->cashier->id,
            'source_hash' => hash('sha256', json_encode($item->only(['stock_quantity', 'retired_at'])))]);
        $this->approve($request);
        $this->post(route('approvals.cancel', $request), ['reason' => 'Delivery corrected'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reviewer)->post(route('approvals.execute', $request), $this->key())->assertSessionHasErrors('approval');
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->actingAs($this->cashier)->post(route('shifts.expense.store'), $this->key(['description' => 'Actual paid expense', 'amount' => 10, 'category' => 'other']))->assertRedirect()->assertSessionHasNoErrors();
        $expenseReview = ApprovalRequest::where('kind', 'expense_review')->firstOrFail();
        $this->post(route('approvals.cancel', $expenseReview), ['reason' => 'Hide expense'])->assertConflict();
        $this->assertSame(90.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
    }

    public function test_two_approved_drops_cannot_overdraw_the_remaining_cash(): void
    {
        $first = $this->requestApproval('cash_drop', $this->shift->id, ['amount' => 70, 'destination' => 'safe', 'reference' => 'DROP-1']);
        $second = $this->requestApproval('cash_drop', $this->shift->id, ['amount' => 70, 'destination' => 'safe', 'reference' => 'DROP-2']);
        $this->approve($first);
        $this->approve($second);
        $this->post(route('approvals.execute', $first), $this->key())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $second), $this->key())->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(30.0, app(FinancialSummaryService::class)->shift($this->shift)['expectedCash']);
    }

    private function folio(): Folio
    {
        $room = Room::where('is_staff_quarters', false)->firstOrFail();
        $room->update(['status' => 'occupied']);

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Approval guest'])->id, 'user_id' => $this->cashier->id, 'transaction_id' => (string) Str::uuid(), 'rate_tier' => 'promo', 'room_charge' => 400, 'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(11), 'status' => 'active']);
    }

    /** @param array<string, mixed> $payload */
    private function requestApproval(string $kind, int $sourceId, array $payload): ApprovalRequest
    {
        $this->post(route('approvals.store'), $this->key(['kind' => $kind, 'source_id' => $sourceId, 'payload' => $payload, 'reason' => 'Verified request']))->assertRedirect()->assertSessionHasNoErrors();

        return ApprovalRequest::latest('id')->firstOrFail();
    }

    private function approve(ApprovalRequest $approval, ?User $actor = null): void
    {
        $this->actingAs($this->reviewer)->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Verified source and amount'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($actor ?? $this->cashier);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function key(array $data = []): array
    {
        return $data + ['operation_key' => (string) Str::uuid()];
    }
}
