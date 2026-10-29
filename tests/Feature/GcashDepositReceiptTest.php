<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\FinancialSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GcashDepositReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-29 19:45:00');
    }

    public function test_gcash_deposit_covering_the_entire_bill_keeps_its_reference_and_gcash_classification_on_the_receipt(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 530, 'gcash', 'TEST-GCASH-42594');
        $this->settle($folio);

        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'kind' => 'deposit', 'cash_amount' => 0, 'gcash_amount' => 530, 'gcash_reference' => 'TEST-GCASH-42594']);
        $this->assertSame('0.00', $folio->fresh()->net_total);
        $summary = app(FinancialSummaryService::class)->period(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(0.0, $summary['cashCollections']);
        $this->assertSame(530.0, $summary['gcashCollections']);

        $this->get(route('folios.receipt', $folio))
            ->assertSee('GCASH E-WALLET')->assertSee('TEST-GCASH-42594')->assertDontSee('CASH PAYMENT');
        $this->post(route('folios.receipt.print', $folio), ['operation_key' => (string) Str::uuid()])
            ->assertSee('GCASH E-WALLET')->assertSee('TEST-GCASH-42594')->assertSee('Paid in full by deposit.');
        $this->get(route('folios.billing', $folio))->assertSee('GCASH E-WALLET')->assertSee('TEST-GCASH-42594')->assertDontSee('CASH PAYMENT');
        $this->assertDatabaseCount('payment_entries', 2);
    }

    public function test_deposit_slip_includes_the_recorded_gcash_transaction_reference(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 530, 'gcash', 'TEST-GCASH-DEPOSIT');

        $this->get(route('folios.deposit_slip', $folio))->assertSee('GCASH')->assertSee('TEST-GCASH-DEPOSIT');
    }

    public function test_gcash_deposit_and_cash_checkout_print_as_split_without_reclassifying_the_posted_money(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 200, 'gcash', 'DEPOSIT-ONLY-REF');
        $this->settle($folio, ['cash_tendered' => 500]);

        $this->get(route('folios.receipt', $folio))->assertSee('SPLIT (CASH + GCASH)')
            ->assertSee('DEPOSIT-ONLY-REF')->assertSee('Additional checkout payment:')->assertSee('₱170.00');
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'kind' => 'settlement', 'cash_amount' => 330, 'gcash_amount' => 0, 'gcash_reference' => null]);
        $this->assertSame('cash', $folio->fresh()->payment_method);
        $summary = app(FinancialSummaryService::class)->period(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(330.0, $summary['cashCollections']);
        $this->assertSame(200.0, $summary['gcashCollections']);
    }

    public function test_multiple_deposits_and_a_separate_gcash_checkout_keep_every_reference(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 100, 'cash');
        $this->deposit($folio, 100, 'gcash', 'FIRST-DEPOSIT-REF');
        $this->deposit($folio, 100, 'gcash', 'SECOND-DEPOSIT-REF');
        $this->settle($folio, ['payment_method' => 'gcash', 'gcash_amount' => 230, 'gcash_reference' => 'CHECKOUT-ONLY-REF']);

        $this->get(route('folios.receipt', $folio))->assertSee('SPLIT (CASH + GCASH)')
            ->assertSee('FIRST-DEPOSIT-REF')->assertSee('SECOND-DEPOSIT-REF')->assertSee('CHECKOUT-ONLY-REF');
        $this->get(route('folios.deposit_slip', $folio))->assertSee('CASH + GCASH')
            ->assertSee('FIRST-DEPOSIT-REF')->assertSee('SECOND-DEPOSIT-REF')->assertDontSee('CHECKOUT-ONLY-REF');
        $this->assertDatabaseCount('payment_entries', 4);
        $this->assertSame(100.0, (float) PaymentEntry::sum('cash_amount'));
        $this->assertSame(430.0, (float) PaymentEntry::sum('gcash_amount'));
    }

    public function test_unapplied_deposit_does_not_reclassify_the_cash_bill_as_a_split_payment(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 530, 'gcash', 'UNAPPLIED-GCASH');
        $this->settle($folio, ['apply_deposit' => 0, 'cash_tendered' => 530]);

        $this->get(route('folios.receipt', $folio))->assertSee('CASH PAYMENT')->assertSee('UNAPPLIED-GCASH')
            ->assertDontSee('SPLIT (CASH + GCASH)')->assertDontSee('Paid in full by deposit.');
        $this->assertSame('not_applied', $folio->fresh()->deposit_status);
    }

    public function test_only_the_applied_portion_of_deposits_determines_the_bill_payment_method(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 600, 'gcash', 'OVERPAYMENT-GCASH');
        $this->deposit($folio, 100, 'cash');
        $this->settle($folio);

        $this->get(route('folios.receipt', $folio))->assertSee('GCASH E-WALLET')->assertSee('OVERPAYMENT-GCASH')
            ->assertSee('₱170.00')->assertDontSee('SPLIT (CASH + GCASH)');
        $this->assertSame(0.0, (float) PaymentEntry::where('kind', 'settlement')->sum('cash_amount'));
    }

    public function test_legacy_deposit_uses_the_saved_channel_without_inventing_a_reference_or_posting_money(): void
    {
        $folio = $this->stay();
        $folio->forceFill(['security_deposit' => 530, 'deposit_payment_method' => 'gcash', 'deposit_status' => 'applied_to_bill',
            'status' => 'checked_out', 'checked_out_at' => now(), 'gross_total' => 530, 'net_total' => 0])->save();

        $this->get(route('folios.receipt', $folio))->assertSee('GCASH E-WALLET')->assertSee('Deposit received (GCASH):')
            ->assertDontSee('GCash deposit ref #:')->assertDontSee('CASH PAYMENT');
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_cash_deposit_still_prints_as_cash(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 530, 'cash');
        $this->settle($folio);

        $this->get(route('folios.receipt', $folio))->assertSee('CASH PAYMENT')->assertSee('Deposit received (CASH):')
            ->assertSee('Paid in full by deposit.')->assertDontSee('GCASH E-WALLET');
    }

    public function test_billing_reprint_keeps_the_original_deposit_snapshot_after_another_payment(): void
    {
        $folio = $this->stay();
        $this->deposit($folio, 200, 'gcash', 'SNAPSHOT-ORIGINAL');
        $print = ['operation_key' => (string) Str::uuid()];
        $this->post(route('folios.billing.print', $folio), $print)->assertSee('SNAPSHOT-ORIGINAL');
        $this->deposit($folio, 330, 'gcash', 'SNAPSHOT-LATER');

        $this->post(route('folios.billing.print', $folio), $print)->assertSee('SNAPSHOT-ORIGINAL')->assertDontSee('SNAPSHOT-LATER');
        $this->post(route('folios.billing.print', $folio), ['operation_key' => (string) Str::uuid()])
            ->assertSee('SNAPSHOT-ORIGINAL')->assertSee('SNAPSHOT-LATER');
        $this->assertDatabaseCount('payment_entries', 2);
    }

    public function test_unpaid_billing_preview_keeps_the_selected_gcash_checkout_method(): void
    {
        $folio = $this->stay();

        $this->get(route('folios.billing', ['folio' => $folio, 'payment_method' => 'gcash']))
            ->assertSee('GCASH E-WALLET')->assertDontSee('CASH PAYMENT');
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_gcash_deposit_without_reference_is_rejected_without_saving_money(): void
    {
        $folio = $this->stay();

        $this->post(route('folios.deposit.collect', $folio), ['amount' => 530, 'payment_method' => 'gcash', 'operation_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('gcash_reference');
        $this->assertSame('0.00', $folio->fresh()->security_deposit);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    private function stay(): Folio
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        foreach (['manage_folios', 'record_payments', 'manage_shifts'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Shift::create(['opened_by' => $actor->id, 'shift_type' => 'night', 'shift_date' => today(), 'opened_at' => now(), 'opening_float' => 0]);
        $room = Room::create(['number' => '18', 'floor' => 1, 'name' => 'Premium', 'type' => 'Premium Room', 'status' => 'available', 'base_rate_3h' => 530]);
        $this->actingAs($actor)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Folio::where('room_id', $room->id)->firstOrFail();
    }

    private function deposit(Folio $folio, float $amount, string $method, ?string $reference = null): void
    {
        $this->post(route('folios.deposit.collect', $folio), ['amount' => $amount, 'payment_method' => $method, 'gcash_reference' => $reference, 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $payment */
    private function settle(Folio $folio, array $payment = []): void
    {
        $this->post(route('checkout.submit', $folio), $payment + ['payment_method' => 'cash', 'cash_tendered' => 0, 'gcash_amount' => 0, 'gcash_reference' => null, 'apply_deposit' => 1, 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }
}
