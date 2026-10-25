<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\DiscountService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptTableFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Room $room;

    protected Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::where('email', 'pau@sedonapms.com')->first() ?? User::first();
        Shift::create(['opened_by' => $this->cashier->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()]);
        $this->room = Room::where('number', '1')->first() ?? Room::first();
        $this->guest = Guest::first() ?? Guest::create(['name' => 'Test Guest', 'contact' => '09171112222', 'headcount' => 1]);
    }

    /**
     * Test 1: Official Receipt uses fixed 4-column layout with Item / Charge, Qty, Price, Total.
     */
    public function test_official_receipt_renders_fixed_four_column_table(): void
    {
        $folio = Folio::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-RCPT-TEST',
            'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(6),
            'expected_checkout_at' => now()->subHours(3),
            'checked_out_at' => now(),
            'status' => 'checked_out',
            'room_charge' => 695.00,
            'extra_hours' => 0,
            'overtime_hours' => 3,
            'pos_items' => [
                ['name' => 'Nova Multigrain Chips (40g)', 'qty' => 1, 'price' => 45.00, 'subtotal' => 45.00],
                ['name' => 'Porksilog', 'qty' => 1, 'price' => 150.00, 'subtotal' => 150.00],
            ],
            'gross_total' => 1280.00,
            'net_total' => 1280.00,
            'payment_method' => 'cash',
            'cash_tendered' => 1500.00,
            'change_due' => 220.00,
        ]);

        $response = $this->actingAs($this->cashier)->get(route('folios.receipt', $folio->id));
        $response->assertOk();

        // 4-column classes and table structure
        $response->assertSee('receipt-table');
        $response->assertSee('col-item');
        $response->assertSee('col-qty');
        $response->assertSee('col-price');
        $response->assertSee('col-total');

        // Header labels
        $response->assertSee('Item / Charge');
        $response->assertSee('Qty');
        $response->assertSee('Price');
        $response->assertSee('Total');

        // Item names and separated figures
        $response->assertSee('Room Base (3h)');
        $response->assertSee('Excess Hours (+3h Overstay)');
        $response->assertSee('Nova Multigrain Chips (40g)');
        $response->assertSee('Porksilog');

        $content = $response->getContent();
        // Guaranteed that Price and Total are separated into distinct td elements
        $this->assertStringContainsString('<td class="col-price font-mono">695.00</td>', $content);
        $this->assertStringContainsString('<td class="col-total font-mono font-bold">695.00</td>', $content);
        $this->assertStringContainsString('<td class="col-price font-mono">130.00</td>', $content);
        $this->assertStringContainsString('<td class="col-total font-mono font-bold">390.00</td>', $content);
        $this->assertStringContainsString('<td class="col-price font-mono">45.00</td>', $content);
        $this->assertStringContainsString('<td class="col-total font-mono font-bold">45.00</td>', $content);
        $this->assertStringContainsString('<td class="col-price font-mono">150.00</td>', $content);
        $this->assertStringContainsString('<td class="col-total font-mono font-bold">150.00</td>', $content);

        // Confirm text squishing never happens
        $this->assertStringNotContainsString('695.00695.00', $content);
        $this->assertStringNotContainsString('130.00390.00', $content);
        $this->assertStringNotContainsString('150.00150.00', $content);
    }

    /**
     * Test 2: Interim Billing Statement uses fixed 4-column layout with Item / Charge, Qty, Price, Total.
     */
    public function test_billing_statement_renders_fixed_four_column_table(): void
    {
        $folio = Folio::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-BILL-TEST',
            'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(6)->subMinutes(16),
            'expected_checkout_at' => now()->subMinutes(16),
            'status' => 'active',
            'room_charge' => 695.00,
            'extra_hours' => 3,
            'pos_items' => [
                ['name' => 'Porksilog', 'qty' => 1, 'price' => 150.00, 'subtotal' => 150.00],
            ],
            'gross_total' => 1365.00,
            'net_total' => 1365.00,
        ]);

        $response = $this->actingAs($this->cashier)->get(route('folios.billing', $folio->id));
        $response->assertOk();

        $response->assertSee('receipt-table');
        $response->assertSee('Item / Charge');
        $response->assertSee('Room Base');
        $response->assertSee('Excess Hours');
        $response->assertSee('Porksilog');

        $content = $response->getContent();
        $this->assertStringContainsString('<td class="col-price font-mono">695.00</td>', $content);
        $this->assertStringContainsString('<td class="col-total font-mono font-bold">695.00</td>', $content);
        $this->assertStringNotContainsString('695.00695.00', $content);
    }

    /**
     * Test 3: Order Slip uses fixed 4-column layout with Item / Charge, Qty, Price, Total.
     */
    public function test_order_slip_renders_fixed_four_column_table(): void
    {
        $folio = Folio::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-ORD-TEST',
            'rate_tier' => '3h',
            'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(3),
            'status' => 'active',
            'room_charge' => 695.00,
            'pos_items' => [
                ['name' => 'Nova Multigrain Chips (40g)', 'qty' => 1, 'price' => 45.00, 'subtotal' => 45.00],
                ['name' => 'Porksilog', 'qty' => 1, 'price' => 150.00, 'subtotal' => 150.00],
            ],
        ]);

        $response = $this->actingAs($this->cashier)->get(route('folios.orderslip', $folio->id));
        $response->assertOk();

        $response->assertSee('receipt-table');
        $response->assertSee('Item');
        $response->assertSee('Nova Multigrain Chips (40g)');
        $response->assertSee('Porksilog');

        $content = $response->getContent();
        $this->assertStringContainsString('<td class="col-price">150.00</td>', $content);
        $this->assertStringContainsString('<td class="col-total font-bold">150.00</td>', $content);
        $this->assertStringNotContainsString('150.00150.00', $content);
    }

    /**
     * Test 4: Interim Billing Statement matches Checkout calculation with Extension and Overtime.
     */
    public function test_billing_statement_matches_checkout_calculation_with_extension_and_overtime(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        try {
            // 17 hours total stay: 3h tier + 3h extension + 11h excess overstay
            $folio = Folio::create([
                'room_id' => $this->room->id,
                'guest_id' => $this->guest->id,
                'user_id' => $this->cashier->id,
                'transaction_id' => 'SCTI-MATCH-01',
                'rate_tier' => '3h',
                'checked_in_at' => now()->subHours(17),
                'expected_checkout_at' => now()->subHours(11),
                'status' => 'active',
                'room_charge' => 695.00,
                'extra_hours' => 3,
                'surcharge_total' => 390.00,
                'pos_items' => [
                    ['name' => 'Nova Multigrain Chips (40g)', 'qty' => 1, 'price' => 45.00, 'subtotal' => 45.00],
                    ['name' => 'Porksilog', 'qty' => 1, 'price' => 150.00, 'subtotal' => 150.00],
                ],
                'discount_type' => 'SENIOR',
                'discount_amount' => 139.00,
                'gross_total' => 1280.00, // old base before 11h overstay
                'net_total' => 1024.00,
            ]);

            // 1. Verify Checkout screen calculates ₱2,710.00 gross and ₱2,571.00 final balance
            $checkoutResp = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
            $checkoutResp->assertOk();
            $checkoutResp->assertSee('₱2,710.00');
            $checkoutResp->assertSee('₱2,571.00');

            // 2. Verify Billing Statement matches EXACT same ₱2,710.00 gross and ₱2,571.00 total amount due
            $billingResp = $this->actingAs($this->cashier)->get(route('folios.billing', $folio->id));
            $billingResp->assertOk();
            $billingResp->assertSee('₱2,710.00');
            $billingResp->assertSee('₱2,571.00');
            $billingResp->assertSee('-₱139.00');

            // Detailed line items present
            $billingResp->assertSee('Stay Extension (+3h Xtend)');
            $billingResp->assertSee('Excess Hours (+11h Overstay)');
            $billingResp->assertSee('Nova Multigrain Chips (40g)');
            $billingResp->assertSee('Porksilog');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test 5: Verify 12% VAT is completely removed from checkout, billing statement, and receipt.
     */
    public function test_vat_is_not_displayed_in_checkout_billing_or_receipt(): void
    {
        $folio = Folio::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'user_id' => $this->cashier->id,
            'transaction_id' => 'SCTI-NOVAT-01',
            'rate_tier' => '3h',
            'checked_in_at' => now()->subHours(3),
            'expected_checkout_at' => now(),
            'status' => 'active',
            'room_charge' => 695.00,
            'gross_total' => 695.00,
            'net_total' => 695.00,
        ]);

        $checkoutResp = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
        $checkoutResp->assertOk();
        $checkoutResp->assertDontSee('12% Inclusive VAT');
        $checkoutResp->assertDontSee('Net Vatable Sales');

        $billingResp = $this->actingAs($this->cashier)->get(route('folios.billing', $folio->id));
        $billingResp->assertOk();
        $billingResp->assertDontSee('12% Inclusive VAT');
        $billingResp->assertDontSee('Net Vatable Sales');

        $receiptResp = $this->actingAs($this->cashier)->get(route('folios.receipt', $folio->id));
        $receiptResp->assertOk();
        $receiptResp->assertDontSee('12% Inclusive VAT');
        $receiptResp->assertDontSee('Net Vatable Sales');
    }

    /**
     * Test 6: Cashier can input deposit and it reflects on billing statement.
     */
    public function test_cashier_can_input_deposit_and_it_reflects_on_billing_statement(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        try {
            $folio = Folio::create([
                'room_id' => $this->room->id,
                'guest_id' => $this->guest->id,
                'user_id' => $this->cashier->id,
                'transaction_id' => 'SCTI-DEP-01',
                'rate_tier' => '3h',
                'checked_in_at' => now()->subHours(2), // within 3h tier, 0 excess hours
                'expected_checkout_at' => now()->addHour(),
                'status' => 'active',
                'room_charge' => 695.00,
                'gross_total' => 695.00,
                'net_total' => 695.00,
                'security_deposit' => 0.00,
            ]);

            // Deposit held is read-only; collecting money is a separate action.
            $checkoutResp = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
            $checkoutResp->assertOk();
            $checkoutResp->assertSee('Record deposit received');
            $checkoutResp->assertSee('id="inpDeposit"', false);
            $checkoutResp->assertDontSee('onDepositChange(this.value)', false);

            $depositUpdateResp = $this->actingAs($this->cashier)->post(route('folios.deposit.collect', $folio->id), [
                'operation_key' => (string) Str::uuid(), 'amount' => 500.00, 'payment_method' => 'cash',
            ]);
            $depositUpdateResp->assertRedirect(route('checkout.process', $folio))->assertSessionHasNoErrors();

            $folio->refresh();
            $this->assertEquals(500.00, (float) $folio->security_deposit);
            $this->assertEquals('applied_to_bill', $folio->deposit_status);

            // 3. Billing statement reflects security deposit deduction and updated total (₱695 - ₱500 = ₱195)
            $billingResp = $this->actingAs($this->cashier)->get(route('folios.billing', $folio->id));
            $billingResp->assertOk();
            $billingResp->assertSee('Less: Security Deposit Applied:');
            $billingResp->assertSee('-₱500.00');
            $billingResp->assertSee('TOTAL AMOUNT DUE:');
            $billingResp->assertSee('₱195.00');

            // Query parameters cannot replace the confirmed deposit used by print previews.
            $liveBillingResp = $this->actingAs($this->cashier)->get(route('folios.billing', ['folio' => $folio->id, 'deposit' => 300.00]));
            $liveBillingResp->assertOk();
            $liveBillingResp->assertSee('Less: Security Deposit Applied:');
            $liveBillingResp->assertSee('-₱500.00');
            $liveBillingResp->assertSee('₱195.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test 7: Split settlement details reflect on statement of billing and receipt.
     */
    public function test_settlement_split_payment_details_reflect_on_billing_statement(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        try {
            $folio = Folio::create([
                'room_id' => $this->room->id,
                'guest_id' => $this->guest->id,
                'user_id' => $this->cashier->id,
                'transaction_id' => 'SCTI-SPLIT-01',
                'rate_tier' => '3h',
                'checked_in_at' => now()->subHours(2),
                'expected_checkout_at' => now()->addHour(),
                'status' => 'active',
                'room_charge' => 2584.00,
                'gross_total' => 2584.00,
                'net_total' => 2584.00,
                'security_deposit' => 0.00,
            ]);

            // 1. Direct query parameters on Statement of Billing (Live Sync from Checkout Screen)
            $billingParams = [
                'folio' => $folio->id,
                'payment_method' => 'split',
                'cash_tendered' => 2000.00,
                'gcash_amount' => 1000.00,
                'gcash_reference' => '10098234821',
                'change_due' => 416.00,
            ];
            $liveBillingResp = $this->actingAs($this->cashier)->get(route('folios.billing', $billingParams));
            $liveBillingResp->assertOk();
            $liveBillingResp->assertSee('SPLIT (CASH + GCASH)');
            $liveBillingResp->assertSee('Cash Tendered:');
            $liveBillingResp->assertSee('₱2,000.00');
            $liveBillingResp->assertSee('GCash Amount Paid:');
            $liveBillingResp->assertSee('₱1,000.00');
            $liveBillingResp->assertSee('GCash Reference #:');
            $liveBillingResp->assertSee('10098234821');
            $liveBillingResp->assertSee('Change Due to Guest:');
            $liveBillingResp->assertSee('₱416.00');

            // 2. Draft persisted to database via AJAX auto-save
            $saveResp = $this->actingAs($this->cashier)->postJson(route('folios.deposit', $folio->id), [
                'payment_method' => 'split',
                'cash_tendered' => 2000.00,
                'gcash_amount' => 1000.00,
                'gcash_reference' => '10098234821',
                'change_due' => 416.00,
            ]);
            $saveResp->assertOk();
            $saveResp->assertJson(['success' => true, 'payment_method' => 'split']);

            // 3. Billing Statement without query parameters reflects persisted database split details
            $dbBillingResp = $this->actingAs($this->cashier)->get(route('folios.billing', $folio->id));
            $dbBillingResp->assertOk();
            $dbBillingResp->assertSee('SPLIT (CASH + GCASH)');
            $dbBillingResp->assertSee('₱2,000.00');
            $dbBillingResp->assertSee('₱1,000.00');
            $dbBillingResp->assertSee('10098234821');
            $dbBillingResp->assertSee('₱416.00');

            // 4. Receipt also reflects split payment details
            $receiptResp = $this->actingAs($this->cashier)->get(route('folios.receipt', $folio->id));
            $receiptResp->assertOk();
            $receiptResp->assertSee('SPLIT (CASH + GCASH)');
            $receiptResp->assertSee('₱2,000.00');
            $receiptResp->assertSee('₱1,000.00');
            $receiptResp->assertSee('10098234821');
            $receiptResp->assertSee('₱416.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test 8: Deposit can be applied to bill; remaining deposit balance after gross sub-total deduction is visible and refundable.
     */
    public function test_deposit_can_be_applied_and_calculates_remaining_deposit_refund(): void
    {
        $fixedNow = Carbon::parse('2026-10-02 12:00:00');
        Carbon::setTestNow($fixedNow);

        try {
            $folio = Folio::create([
                'room_id' => $this->room->id,
                'guest_id' => $this->guest->id,
                'user_id' => $this->cashier->id,
                'transaction_id' => 'SCTI-DEP-APPLY',
                'rate_tier' => '3h',
                'checked_in_at' => $fixedNow->copy()->subHours(2),
                'expected_checkout_at' => $fixedNow->copy()->addHour(),
                'status' => 'active',
                'room_charge' => 695.00,
                'extra_hours' => 0,
                'pos_items' => [],
                'gross_total' => 695.00,
                'net_total' => 695.00,
                'security_deposit' => 1000.00,
                'deposit_status' => 'applied_to_bill',
            ]);

            // 1. Checkout Process UI shows checkbox checked and remaining deposit balance ₱305.00
            $checkoutResp = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
            $checkoutResp->assertOk();
            $checkoutResp->assertSee('chkApplyDeposit');
            $checkoutResp->assertSee('REMAINING DEPOSIT BALANCE:');
            $checkoutResp->assertSee('₱305.00');
            $checkoutResp->assertSee('REMAINING DEPOSIT TO REFUND:');
            $checkoutResp->assertSee('-₱695.00'); // Less security deposit applied

            // 2. Statement of Account / Billing with deposit applied
            $billingResp = $this->actingAs($this->cashier)->get(route('folios.billing', [
                'folio' => $folio->id,
                'apply_deposit' => '1',
                'deposit' => 1000.00,
            ]));
            $billingResp->assertOk();
            $billingResp->assertSee('Less: Security Deposit Applied:');
            $billingResp->assertSee('-₱695.00');
            $billingResp->assertSee('Remaining Deposit to Refund:');
            $billingResp->assertSee('₱305.00');
            $billingResp->assertSee('TOTAL AMOUNT DUE:');
            $billingResp->assertSee('₱0.00');

            // 3. Receipt with deposit applied
            $receiptResp = $this->actingAs($this->cashier)->get(route('folios.receipt', [
                'folio' => $folio->id,
                'apply_deposit' => '1',
                'deposit' => 1000.00,
            ]));
            $receiptResp->assertOk();
            $receiptResp->assertSee('Less: Security Deposit Applied:');
            $receiptResp->assertSee('-₱695.00');
            $receiptResp->assertSee('Remaining Deposit to Refund:');
            $receiptResp->assertSee('₱305.00');
            $receiptResp->assertSee('NET AMOUNT DUE:');
            $receiptResp->assertSee('₱0.00');

            // 4. The refund slip distinguishes the remaining liability from an actual refund.
            $refundResp = $this->actingAs($this->cashier)->get(route('folios.deposit_refund', [
                'folio' => $folio->id,
                'deposit' => 1000.00,
                'apply_deposit' => '1',
            ]));
            $refundResp->assertOk();
            $refundResp->assertSee('Original Deposit Held:');
            $refundResp->assertSee('₱1,000.00');
            $refundResp->assertSee('Deductions / Damages / POS:');
            $refundResp->assertSee('₱695.00');
            $refundResp->assertSee('TOTAL DEPOSIT REFUNDED:');
            $refundResp->assertSee('₱0.00');
            $refundResp->assertSee('No deposit refund has been recorded.');
            $refundResp->assertSee('₱305.00');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test 9: Cashier has option NOT to apply deposit; bill stays intact and full deposit is refundable.
     */
    public function test_deposit_option_not_applied_preserves_full_bill_and_full_deposit(): void
    {
        $fixedNow = Carbon::parse('2026-10-02 12:00:00');
        Carbon::setTestNow($fixedNow);

        try {
            $folio = Folio::create([
                'room_id' => $this->room->id,
                'guest_id' => $this->guest->id,
                'user_id' => $this->cashier->id,
                'transaction_id' => 'SCTI-DEP-NO-APPLY',
                'rate_tier' => '3h',
                'checked_in_at' => $fixedNow->copy()->subHours(2),
                'expected_checkout_at' => $fixedNow->copy()->addHour(),
                'status' => 'active',
                'room_charge' => 695.00,
                'extra_hours' => 0,
                'pos_items' => [],
                'gross_total' => 695.00,
                'net_total' => 695.00,
                'security_deposit' => 1000.00,
                'deposit_status' => 'not_applied',
            ]);

            // 1. Checkout Process UI reflects unapplied deposit
            $checkoutResp = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
            $checkoutResp->assertOk();
            $checkoutResp->assertSee('✕ Do Not Apply');
            $checkoutResp->assertSee('₱0.00 (NOT APPLIED)');
            $checkoutResp->assertSee('₱1,000.00'); // Full remaining deposit
            $checkoutResp->assertSee('₱695.00'); // Final balance to pay

            // 2. Billing Statement shows unapplied deposit and full amount due
            $billingResp = $this->actingAs($this->cashier)->get(route('folios.billing', [
                'folio' => $folio->id,
                'apply_deposit' => '0',
                'deposit' => 1000.00,
            ]));
            $billingResp->assertOk();
            $billingResp->assertSee('Security Deposit (Not Applied):');
            $billingResp->assertSee('₱1,000.00');
            $billingResp->assertSee('Remaining Deposit to Refund:');
            $billingResp->assertSee('₱1,000.00');
            $billingResp->assertSee('TOTAL AMOUNT DUE:');
            $billingResp->assertSee('₱695.00');

            // 3. Receipt shows unapplied deposit and full net amount due
            $receiptResp = $this->actingAs($this->cashier)->get(route('folios.receipt', [
                'folio' => $folio->id,
                'apply_deposit' => '0',
                'deposit' => 1000.00,
            ]));
            $receiptResp->assertOk();
            $receiptResp->assertSee('Security Deposit (Not Applied):');
            $receiptResp->assertSee('₱1,000.00');
            $receiptResp->assertSee('Remaining Deposit to Refund:');
            $receiptResp->assertSee('₱1,000.00');
            $receiptResp->assertSee('NET AMOUNT DUE:');
            $receiptResp->assertSee('₱695.00');

            // 4. Finalize Checkout with apply_deposit=0
            $postResp = $this->actingAs($this->cashier)->post(route('checkout.submit', $folio->id), ['operation_key' => (string) Str::uuid(),
                'security_deposit' => 1000.00,
                'apply_deposit' => '0',
                'payment_method' => 'cash',
                'cash_tendered' => 1000.00,
            ]);
            $postResp->assertRedirect(route('dashboard'));

            $folio->refresh();
            $this->assertEquals('checked_out', $folio->status);
            $this->assertEquals('not_applied', $folio->deposit_status);
            $this->assertEquals(695.00, (float) $folio->net_total);
            $this->assertEquals(305.00, (float) $folio->change_due); // 1000 tendered - 695 bill = 305 change
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test 10: Cashier can select discount in checkout process screen and it recalculates and reflects across billing and receipt.
     */
    public function test_cashier_can_select_discount_during_checkout_process_and_it_reflects_everywhere(): void
    {
        $fixedNow = Carbon::parse('2026-10-02 12:00:00');
        Carbon::setTestNow($fixedNow);

        try {
            $folio = Folio::create([
                'room_id' => $this->room->id,
                'guest_id' => $this->guest->id,
                'user_id' => $this->cashier->id,
                'transaction_id' => 'SCTI-DISC-CHCK',
                'rate_tier' => '3h',
                'checked_in_at' => $fixedNow->copy()->subHours(2),
                'expected_checkout_at' => $fixedNow->copy()->addHour(),
                'status' => 'active',
                'room_charge' => 695.00,
                'extra_hours' => 0,
                'pos_items' => [],
                'gross_total' => 695.00,
                'discount_amount' => 0.00,
                'discount_type' => 'NONE',
                'net_total' => 695.00,
                'security_deposit' => 0.00,
            ]);

            $options = DiscountService::getOptionsForRoom($this->room->type, '3h');
            $seniorD = $options['SENIOR'];
            $pwdD = $options['PWD'];
            $dcD = $options['DC'];
            $seniorNet = 695.00 - $seniorD;
            $pwdNet = 695.00 - $pwdD;
            $pwdChange = 1000.00 - $pwdNet;

            // 1. Checkout Process UI renders discount selector and reference input
            $checkoutResp = $this->actingAs($this->cashier)->get(route('checkout.process', $folio->id));
            $checkoutResp->assertOk();
            $checkoutResp->assertSee('discountTypeSelect');
            $checkoutResp->assertSee('DISCOUNT CLASSIFICATION:');
            $checkoutResp->assertSee('Senior Citizen (-₱'.number_format($seniorD, 2).')');
            $checkoutResp->assertSee('PWD Disability (-₱'.number_format($pwdD, 2).')');
            $checkoutResp->assertSee('Sedona Card / DC (-₱'.number_format($dcD, 2).')');
            $checkoutResp->assertSee('inpDiscountRef');

            // 2. Cashier selects discount and it auto-saves via AJAX draft endpoint
            $saveResp = $this->actingAs($this->cashier)->postJson(route('folios.deposit', $folio->id), [
                'discount_type' => 'senior',
                'discount_id_ref' => 'OSCA-99128',
            ]);
            $saveResp->assertOk();
            $saveResp->assertJson([
                'success' => true,
                'discount_type' => 'SENIOR',
                'discount_id_ref' => 'OSCA-99128',
                'discount_amount' => $seniorD,
            ]);

            $folio->refresh();
            $this->assertEquals('SENIOR', $folio->discount_type);
            $this->assertEquals('OSCA-99128', $folio->discount_id_ref);
            $this->assertEquals($seniorD, (float) $folio->discount_amount);

            // 3. Billing Statement reflects the selected discount and adjusted total
            $billingResp = $this->actingAs($this->cashier)->get(route('folios.billing', $folio->id));
            $billingResp->assertOk();
            $billingResp->assertSee('Less: Senior Statutory Table (OSCA-99128):');
            $billingResp->assertSee('-₱'.number_format($seniorD, 2));
            $billingResp->assertSee('TOTAL AMOUNT DUE:');
            $billingResp->assertSee('₱'.number_format($seniorNet, 2));

            // 4. Receipt reflects the selected discount and adjusted net total
            $receiptResp = $this->actingAs($this->cashier)->get(route('folios.receipt', $folio->id));
            $receiptResp->assertOk();
            $receiptResp->assertSee('Less: Senior Statutory Table (OSCA-99128):');
            $receiptResp->assertSee('-₱'.number_format($seniorD, 2));
            $receiptResp->assertSee('NET AMOUNT DUE:');
            $receiptResp->assertSee('₱'.number_format($seniorNet, 2));

            // 5. Finalize Official Checkout with PWD discount instead
            $postResp = $this->actingAs($this->cashier)->post(route('checkout.submit', $folio->id), ['operation_key' => (string) Str::uuid(),
                'discount_type' => 'pwd',
                'discount_id_ref' => 'PWD-88214',
                'payment_method' => 'cash',
                'cash_tendered' => 1000.00,
            ]);
            $postResp->assertRedirect(route('dashboard'));

            $folio->refresh();
            $this->assertEquals('checked_out', $folio->status);
            $this->assertEquals('PWD', $folio->discount_type);
            $this->assertEquals('PWD-88214', $folio->discount_id_ref);
            $this->assertEquals($pwdD, (float) $folio->discount_amount);
            $this->assertEquals($pwdNet, (float) $folio->net_total);
            $this->assertEquals($pwdChange, (float) $folio->change_due);
        } finally {
            Carbon::setTestNow();
        }
    }
}
