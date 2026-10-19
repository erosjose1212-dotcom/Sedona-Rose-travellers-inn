<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionHistoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_history_keeps_legacy_settlements_with_no_checkout_operator(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('is_staff_quarters', false)->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Historical guest'])->id,
            'user_id' => $cashier->id, 'transaction_id' => 'SCTI-LEGACY-QA', 'status' => 'checked_out', 'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(3), 'expected_checkout_at' => now(), 'checked_out_at' => now(),
            'checked_out_by' => null, 'payment_method' => 'cash', 'cash_tendered' => 500, 'change_due' => 105]);

        $this->actingAs($cashier)->get(route('transactions.index'))->assertOk()->assertSee($folio->transaction_id)
            ->assertViewHas('transactions', fn ($rows): bool => $rows->total() === 1 && (float) $rows->first()->cash_amount === 395.0);
        $other = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $this->actingAs($other)->get(route('transactions.index'))->assertOk()->assertDontSee($folio->transaction_id);
    }

    public function test_history_keeps_actual_pending_rejected_and_reversed_expenses_visible(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        foreach (['pending_review' => 50, 'rejected_paid' => 20, 'approved' => -10] as $status => $amount) {
            Expense::create(['user_id' => $cashier->id, 'voucher_number' => Expense::generateVoucherNumber(), 'expense_date' => today(),
                'category' => 'other', 'description' => $status, 'amount' => $amount, 'payment_source' => 'cash_drawer', 'status' => $status]);
        }

        $this->actingAs($cashier)->get(route('transactions.index').'?kind=drawer_expense')->assertOk()
            ->assertViewHas('transactions', fn ($records): bool => $records->total() === 3 && (float) $records->sum('cash_amount') === -60.0);
    }

    public function test_history_includes_own_custody_movements_without_exposing_another_cashier(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $other = User::factory()->create();
        $shift = Shift::create(['opened_by' => $cashier->id, 'opened_at' => now(), 'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 200]);
        CashMovement::factory()->create(['user_id' => $cashier->id, 'shift_id' => $shift->id, 'kind' => 'bank_deposit', 'destination' => 'bank', 'amount' => -100, 'reference' => 'OWN-BANK-DEPOSIT']);
        CashMovement::factory()->create(['user_id' => $other->id, 'shift_id' => $shift->id, 'reference' => 'OTHER-CASHIER-DROP']);

        $this->actingAs($cashier)->get(route('transactions.index').'?kind=bank_deposit')->assertOk()->assertSee('OWN-BANK-DEPOSIT')->assertDontSee('OTHER-CASHIER-DROP')
            ->assertViewHas('transactions', fn ($records): bool => $records->total() === 1 && (float) $records->first()->cash_amount === -100.0);
    }

    public function test_history_identifies_matched_gcash_and_filters_pos_refunds(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $entry = PaymentEntry::create(['user_id' => $cashier->id, 'kind' => 'pos_refund', 'cash_amount' => 0, 'gcash_amount' => -25, 'gcash_reference' => 'REFUND-MATCH', 'posted_at' => now()]);
        $statement = StatementLine::factory()->create(['user_id' => $cashier->id, 'reference' => 'REFUND-MATCH', 'gross_amount' => -25, 'fee_amount' => 0, 'net_amount' => -25]);
        DB::table('statement_matches')->insert(['statement_line_id' => $statement->id, 'payment_entry_id' => $entry->id, 'amount' => -25, 'user_id' => $cashier->id, 'created_at' => now()]);

        $this->actingAs($cashier)->get(route('transactions.index').'?kind=pos_refund')->assertOk()->assertSee('Matched to imported statement')->assertDontSee('provider verification pending')
            ->assertViewHas('transactions', fn ($records): bool => $records->total() === 1);
    }
}
