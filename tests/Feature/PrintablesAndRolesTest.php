<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BarcodeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintablesAndRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_barcode_service_encodes_code128_svg()
    {
        $svg = BarcodeService::renderSvg('SCTI-100234', 36);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }

    public function test_exit_gate_pass_printable()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::where('number', '1')->first();
        $guest = Guest::first() ?? Guest::create(['name' => 'Maria Clara']);

        $folio = Folio::create([
            'transaction_id' => 'SCTI-998877',
            'room_id' => $room->id,
            'user_id' => $cashier->id,
            'guest_id' => $guest->id,
            'status' => 'checked_out',
            'rate_tier' => '3h',
            'room_charge' => 395,
            'gross_total' => 395,
            'net_total' => 395,
            'checked_in_at' => now()->subHours(3),
            'checked_out_at' => now(),
            'expected_checkout_at' => now(),
            'payment_method' => 'cash',
        ]);

        $response = $this->actingAs($cashier)->get(route('folios.gate_pass', $folio));
        $response->assertStatus(200);
        $response->assertSee('GATE PASS');
        $response->assertSee('CLEARED FOR EXIT');
        $response->assertSee('ROOM 1');
        $response->assertSee('SEDONA ROSE');
    }

    public function test_security_deposit_refund_slip()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::where('number', '2')->first();
        $guest = Guest::first() ?? Guest::create(['name' => 'Maria Clara']);

        $folio = Folio::create([
            'transaction_id' => 'SCTI-998878',
            'room_id' => $room->id,
            'user_id' => $cashier->id,
            'guest_id' => $guest->id,
            'status' => 'checked_out',
            'rate_tier' => '3h',
            'room_charge' => 395,
            'gross_total' => 395,
            'net_total' => 395,
            'checked_in_at' => now()->subHours(3),
            'checked_out_at' => now(),
            'expected_checkout_at' => now(),
            'payment_method' => 'cash',
            'security_deposit' => 500.00,
        ]);

        $response = $this->actingAs($cashier)->get(route('folios.deposit_refund', $folio));
        $response->assertStatus(200);
        $response->assertSee('SECURITY DEPOSIT REFUND / RESOLUTION');
        $response->assertSee('TOTAL DEPOSIT REFUNDED');
        $response->assertSee('500.00');
    }

    public function test_shift_remittance_slip()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $shift = Shift::first() ?? Shift::create([
            'opened_by' => $cashier->id,
            'shift_type' => 'day',
            'shift_date' => now()->toDateString(),
            'opened_at' => now(),
            'is_frozen' => false,
        ]);

        $response = $this->actingAs($cashier)->get(route('shifts.remittance_slip', $shift));
        $response->assertStatus(200);
        $response->assertSee('SHIFT DRAWER RECONCILIATION REPORT');
        $response->assertSee('I. RECONCILIATION SUMMARY');
        $response->assertSee('OPERATIONAL EXPENSES');
        $response->assertSee('ROOM OCCUPANCY REPORT');
    }

    public function test_kitchen_tv_display_view()
    {
        $kitchenUser = User::where('email', 'kitchen1@sedonapms.com')->first();
        $response = $this->actingAs($kitchenUser)->get(route('kitchen.tv'));
        $response->assertStatus(200);
        $response->assertSee('Kitchen TV Queue');
        $response->assertSee('FIFO Live Queue');
    }
}
