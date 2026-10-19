<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositSlipAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_deposit_slip_reflects_actual_deposit_and_omits_removed_text()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::first();
        $guest = Guest::first() ?? Guest::create(['name' => 'Deposit Test Guest']);

        $folio = Folio::create([
            'transaction_id' => 'SCTI-TEST-001',
            'room_id' => $room->id,
            'user_id' => $cashier->id,
            'guest_id' => $guest->id,
            'status' => 'active',
            'rate_tier' => '3h',
            'room_charge' => 395,
            'gross_total' => 395,
            'net_total' => 395,
            'checked_in_at' => now()->subHours(2),
            'expected_checkout_at' => now()->addHour(),
            'payment_method' => 'cash',
            'security_deposit' => 0.00,
            'deposit_status' => 'none',
        ]);

        $response = $this->actingAs($cashier)->get(route('folios.deposit_slip', $folio->id));

        $response->assertStatus(200);
        $response->assertSee('SECURITY DEPOSIT AMOUNT HELD IN TRUST:');
        $response->assertSee('₱0.00');
        $response->assertSee('NONE');

        // Verify requested removals
        $response->assertDontSee('(Philippine Pesos • Room & Amenity Security)');
        $response->assertDontSee('1. Room Security Deposit:');
        $response->assertDontSee('[ AUTOMATIC CREDIT ]');
        $response->assertDontSee('TERMS & INDEMNITY AGREEMENT:');
        $response->assertDontSee('Missing keycard or damaged property');

        // A printable cannot fabricate a deposit collection through its query string.
        $responseWithDeposit = $this->actingAs($cashier)->get(route('folios.deposit_slip', ['folio' => $folio->id, 'deposit' => 250]));
        $responseWithDeposit->assertStatus(200);
        $responseWithDeposit->assertSee('₱0.00');
        $responseWithDeposit->assertDontSee('₱250.00');
    }

    public function test_billing_statement_uses_recorded_check_in_time_and_approved_room_charge(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::first();
        $guest = Guest::first() ?? Guest::create(['name' => 'Billing Test Guest']);

        $cIn = now()->subHours(5);
        $folio = Folio::create([
            'transaction_id' => 'SCTI-TEST-002',
            'room_id' => $room->id,
            'user_id' => $cashier->id,
            'guest_id' => $guest->id,
            'status' => 'active',
            'rate_tier' => '3h',
            'room_charge' => 395,
            'gross_total' => 395,
            'net_total' => 395,
            'checked_in_at' => $cIn,
            'expected_checkout_at' => $cIn->copy()->addHours(3),
            'payment_method' => 'cash',
            'security_deposit' => 0.00,
        ]);

        $folio->update(['room_charge' => 500]);
        $newCheckIn = now()->subHours(10)->startOfMinute();
        $newExpectedOut = $cIn->copy()->addHours(3);

        $response = $this->actingAs($cashier)->get(route('folios.billing', [
            'folio' => $folio->id,
            'checked_in_at' => $newCheckIn->toIso8601String(),
            'room_charge' => 500,
            'deposit' => 0,
            'apply_deposit' => 1,
            'discount_type' => 'none',
            'payment_method' => 'cash',
        ]));

        $response->assertStatus(200);
        $response->assertSee($cIn->format('m/d/Y h:i A'));
        $response->assertDontSee($newCheckIn->format('m/d/Y h:i A'));
        $response->assertSee($newExpectedOut->format('m/d/Y h:i A'));
        $response->assertSee('Check-Out:');
        $response->assertSee('Total time used:');
        $response->assertSee('Excess Overtime:');
        $response->assertSee('500.00');
        $response->assertSee('Less: Security Deposit:');
        $response->assertSee('Cash Tendered:');
        $response->assertSee('Change Due to Guest:');
    }

    public function test_checkin_store_stamps_current_manila_time(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first() ?? User::first();
        $room = Room::where('number', '5')->first();

        $this->travelTo('2026-10-13 12:00:00');
        $customTime = now()->format('Y-m-d\TH:i');
        $resp = $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Custom Time Guest',
            'headcount' => 2,
            'rate_tier' => '3h',
        ]);

        $resp->assertRedirect(route('dashboard'));
        $folio = Folio::where('room_id', $room->id)->where('status', 'active')->first();
        $this->assertNotNull($folio);
        $this->assertEquals(Carbon::parse($customTime)->format('Y-m-d H:i'), $folio->checked_in_at->format('Y-m-d H:i'));
        $this->assertEquals(Carbon::parse($customTime)->addHours(3)->format('Y-m-d H:i'), $folio->expected_checkout_at->format('Y-m-d H:i'));
    }
}
