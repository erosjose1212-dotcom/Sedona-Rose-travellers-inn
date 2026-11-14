<?php

namespace Tests\Feature;

use App\Models\BusinessDay;
use App\Models\CashMovement;
use App\Models\PaymentEntry;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\BusinessDayService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-22 12:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_review_shows_only_selected_day_statements_and_eligible_gcash_choices(): void
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $line = StatementLine::factory()->create(['user_id' => $admin->id, 'reference' => 'MATCH-CHOICES']);
        StatementLine::factory()->create(['user_id' => $admin->id, 'reference' => 'ANOTHER-DAY', 'business_date' => '2026-10-21']);
        $eligible = $this->payment($cashier, 100, $line->reference);
        $this->payment($admin, 100, $line->reference);
        $this->payment($cashier, 100, 'WRONG-REFERENCE');
        $used = $this->payment($cashier, 100, $line->reference);
        $usedLine = StatementLine::factory()->create(['user_id' => $admin->id]);
        DB::table('statement_matches')->insert(['statement_line_id' => $usedLine->id, 'payment_entry_id' => $used->id, 'amount' => 100, 'user_id' => $admin->id, 'created_at' => now()]);

        $this->actingAs($admin)->get(route('admin.finance.index', ['date' => '2026-10-22']))
            ->assertOk()->assertDontSee('ANOTHER-DAY')
            ->assertViewHas('paymentCandidates', fn ($choices): bool => $choices->get('MATCH-CHOICES')->modelKeys() === [$eligible->id])
            ->assertSee('name="entries[]"', false)->assertSee('Net GCash collected')
            ->assertSee('6:30 AM cutoff, Manila time')
            ->assertViewHas('cutoffPassed', false);
    }

    #[TestWith(['gcash', 60, 40])]
    #[TestWith(['gcash', -60, -40])]
    #[TestWith(['bank', 60, 40])]
    public function test_checkbox_selection_matches_exact_signed_total_and_retry_is_idempotent(string $provider, int $firstAmount, int $secondAmount): void
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $line = StatementLine::factory()->create(['provider' => $provider, 'reference' => 'SELECTED-ROWS', 'gross_amount' => $firstAmount + $secondAmount, 'fee_amount' => 0, 'net_amount' => $firstAmount + $secondAmount, 'user_id' => $admin->id]);
        $sources = collect([$firstAmount, $secondAmount])->map(fn (int $amount) => $provider === 'gcash'
            ? $this->payment($cashier, $amount, $line->reference)
            : CashMovement::factory()->create(['kind' => 'bank_deposit', 'user_id' => $cashier->id, 'amount' => -$amount, 'reference' => $line->reference]));
        $this->actingAs($admin)->get(route('admin.finance.index'))->assertOk()->assertSee('SELECTED-ROWS')->assertSee('name="entries[]"', false);
        $data = ['operation_key' => (string) Str::uuid(), 'entries' => $sources->pluck('id')->all()];

        $this->post(route('admin.finance.match', $line), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.finance.match', $line), $data)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('statement_matches', 2);
        $this->assertSame((float) ($firstAmount + $secondAmount), (float) DB::table('statement_matches')->sum('amount'));
        $this->get(route('admin.finance.index'))->assertViewHas('matchedStatementIds', [$line->id])->assertDontSee('name="entries[]"', false);
    }

    #[TestWith(['wrong_total'])]
    #[TestWith(['own_payment'])]
    #[TestWith(['invalid_id'])]
    public function test_invalid_checkbox_matches_do_not_write_any_matches(string $case): void
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $line = StatementLine::factory()->create(['reference' => 'GUARDED', 'user_id' => $admin->id]);
        $payment = $this->payment($case === 'own_payment' ? $admin : $cashier, $case === 'wrong_total' ? 90 : 100, $line->reference);
        $data = ['operation_key' => (string) Str::uuid(), 'entries' => [$case === 'invalid_id' ? 'invalid' : $payment->id]];

        $response = $this->actingAs($admin)->post(route('admin.finance.match', $line), $data);
        if ($case === 'own_payment') {
            $response->assertForbidden();
        } else {
            $response->assertSessionHasErrors($case === 'invalid_id' ? 'entries.0' : 'entries');
        }
        $this->assertDatabaseCount('statement_matches', 0);
    }

    public function test_closed_day_displays_saved_totals_and_does_not_offer_matching(): void
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $line = StatementLine::factory()->create(['user_id' => $admin->id]);
        $this->payment(User::where('email', 'pau@sedonapms.com')->firstOrFail(), 100, $line->reference);
        $snapshot = app(BusinessDayService::class)->snapshot('2026-10-22');
        $snapshot['gcash_cents'] = 43210;
        BusinessDay::whereDate('business_date', '2026-10-22')->delete();
        BusinessDay::factory()->create(['business_date' => '2026-10-22', 'status' => 'closed', 'version' => 1, 'snapshot' => $snapshot]);

        $this->actingAs($admin)->get(route('admin.finance.index'))->assertOk()
            ->assertSee('Closed · saved totals')->assertSee('432.10')
            ->assertDontSee('name="entries[]"', false)->assertDontSee('Import statement')
            ->assertSee('Prepare CSV for this day');
    }

    public function test_cashier_cannot_access_finance_or_submit_checkbox_matches(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $line = StatementLine::factory()->create(['user_id' => $cashier->id]);
        $payment = $this->payment($cashier, 100, $line->reference);

        $this->actingAs($cashier)->get(route('admin.finance.index'))->assertForbidden();
        $this->post(route('admin.finance.match', $line), ['operation_key' => (string) Str::uuid(), 'entries' => [$payment->id]])->assertForbidden();
        $this->assertDatabaseCount('statement_matches', 0);
    }

    public function test_turnover_preview_can_print_cash_and_gcash_for_the_selected_shift(): void
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $shift = Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => '2026-10-21', 'opened_at' => '2026-10-21 06:30:00', 'closed_at' => '2026-10-21 18:30:00', 'opening_float' => 1000, 'cash_total' => 1200, 'expected_cash' => 1200, 'gcash_total' => 300]);
        PaymentEntry::create(['shift_id' => $shift->id, 'user_id' => $cashier->id, 'kind' => 'settlement', 'cash_amount' => 200, 'gcash_amount' => 300, 'posted_at' => '2026-10-21 12:00:00']);
        $this->payment($cashier, 999, 'ANOTHER-SHIFT');

        $this->actingAs($admin)->get(route('shifts.remittance_slip', $shift))->assertOk()
            ->assertSee('Print 80mm Shift Remittance')->assertSee('onclick="window.print()"', false)
            ->assertViewHas('cashSales', 200.0)->assertViewHas('gcashSales', 300.0)
            ->assertViewHas('expectedCash', 1200.0)->assertSee('80mm auto');
    }

    private function payment(User $user, int $amount, string $reference): PaymentEntry
    {
        return PaymentEntry::create(['user_id' => $user->id, 'kind' => $amount < 0 ? 'pos_refund' : 'pos_sale', 'cash_amount' => 0, 'gcash_amount' => $amount, 'gcash_reference' => $reference, 'posted_at' => now(), 'business_date' => '2026-10-22']);
    }
}
