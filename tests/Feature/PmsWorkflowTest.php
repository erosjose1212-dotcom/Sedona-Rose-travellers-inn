<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Order;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PmsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_cashier_can_access_dashboard_and_checkin_guest()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::where('number', '1')->where('status', 'available')->first();

        $response = $this->actingAs($cashier)->get(route('dashboard'));
        $response->assertStatus(200);
        $response->assertSee('AVAILABLE ROOMS');
        $response->assertSee('OCCUPIED ROOMS');

        // Check in guest to Room 1
        $checkinResponse = $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Juan Dela Cruz',
            'rate_tier' => '12h',
            'headcount' => 2,
            'is_senior' => 0,
        ]);

        $checkinResponse->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'status' => 'occupied',
        ]);
        $this->assertDatabaseHas('folios', [
            'room_id' => $room->id,
            'rate_tier' => '12h',
            'status' => 'active',
        ]);
    }

    public function test_room_transfer_workflow()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $sourceRoom = Room::where('number', '1')->where('status', 'available')->first();
        $targetRoom = Room::where('number', '3')->where('status', 'available')->first();

        // Check in Room 1
        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $sourceRoom->id,
            'guest_name' => 'Transfer Guest',
            'rate_tier' => '3h',
            'headcount' => 1,
        ]);

        $folio = Folio::where('room_id', $sourceRoom->id)->where('status', 'active')->first();
        $this->assertNotNull($folio);

        // Perform Room Transfer
        $transferResponse = $this->actingAs($cashier)->post(route('folios.transfer', $folio->id), [
            'target_room_id' => $targetRoom->id,
            'reason' => 'Air conditioning issue in Room 1',
        ]);

        $transferResponse->assertRedirect(route('dashboard'));
        $this->assertEquals('available', $sourceRoom->fresh()->status);
        $this->assertEquals('occupied', $targetRoom->fresh()->status);
        $this->assertEquals($targetRoom->id, $folio->fresh()->room_id);
    }

    public function test_kitchen_kds_workflow()
    {
        $kitchenUser = User::where('email', 'kitchen1@sedonapms.com')->first();
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $room = Room::where('number', '1')->where('status', 'available')->first();

        // Check in room
        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Food Guest',
            'rate_tier' => '12h',
            'headcount' => 2,
        ]);

        $folio = Folio::where('room_id', $room->id)->where('status', 'active')->first();
        $posItem = PosItem::first();

        // Place POS order for Room 1
        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid(),
            'order_type' => 'room_charge',
            'room_id' => $room->id,
            'folio_id' => $folio->id,
            'items' => [
                ['id' => $posItem->id, 'qty' => 2],
            ],
            'special_instructions' => 'Serve hot',
        ]);

        $order = Order::where('folio_id', $folio->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('new', $order->status);

        // Kitchen views KDS
        $kdsResponse = $this->actingAs($kitchenUser)->get(route('kitchen.view'));
        $kdsResponse->assertStatus(200);
        $kdsResponse->assertSee($order->transaction_id);

        // Kitchen transitions order: new -> preparing -> ready -> delivered
        $this->actingAs($kitchenUser)->post(route('pos.order.status', $order->id), [
            'status' => 'preparing',
        ]);
        $this->assertEquals('preparing', $order->fresh()->status);

        $this->actingAs($kitchenUser)->post(route('pos.order.status', $order->id), [
            'status' => 'ready',
        ]);
        $this->assertEquals('ready', $order->fresh()->status);

        $this->actingAs($kitchenUser)->post(route('pos.order.status', $order->id), [
            'status' => 'delivered',
        ]);
        $this->assertEquals('delivered', $order->fresh()->status);
    }

    public function test_admin_force_checkout_approval_workflow()
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->first();
        $admin = User::where('email', 'admin1@sedonapms.com')->first();
        $room = Room::where('number', '5')->where('status', 'available')->first();

        // Check in room 5
        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Skip Guest',
            'rate_tier' => '3h',
            'headcount' => 1,
        ]);

        $folio = Folio::where('room_id', $room->id)->where('status', 'active')->first();
        $this->assertNotNull($folio);

        // Cashier requests force checkout
        $forceReqResponse = $this->actingAs($cashier)->post(route('folios.force_checkout', $folio->id), ['operation_key' => (string) Str::uuid(),
            'force_reason' => 'Guest skipped out without settlement',
        ]);

        $forceReqResponse->assertRedirect(route('approvals.index'));
        $this->assertTrue((bool) $folio->fresh()->force_checkout);
        $this->assertEquals('Guest skipped out without settlement', $folio->fresh()->force_reason);

        // Admin approves force checkout
        $this->executeLatestFinancialRequest();

        $this->assertEquals('checked_out', $folio->fresh()->status);
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_lobby_kiosk_public_view()
    {
        $response = $this->get(route('kiosk.view'));
        $response->assertStatus(200);
        $response->assertSee('SEDONA ROSE');
        $response->assertSee('CLASSIC ROOM');
        $response->assertSee('PREMIUM ROOM');
        $response->assertSee('VIP SUITE ROOM');
    }

    public function test_admin_pricing_and_reports_access()
    {
        $admin = User::where('email', 'admin1@sedonapms.com')->first();
        $owner = User::where('email', 'owner@sedonapms.com')->first();

        // Admin pricing editor
        $pricingResponse = $this->actingAs($admin)->get(route('admin.pricing.index'));
        $pricingResponse->assertStatus(200);
        $pricingResponse->assertSee('ROOM STAY RATES MAINTENANCE');

        // Owner reports
        $reportsResponse = $this->actingAs($owner)->get(route('reports.index'));
        $reportsResponse->assertStatus(200);
        $reportsResponse->assertSee('WEEKLY REVENUE BREAKDOWN');
        $reportsResponse->assertSee('OPERATING EXPENSES');
    }
}
