<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Services\BillingService;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MidnightPromoDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-14 20:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail());
    }

    public function test_promo_cannot_be_selected_or_submitted_for_any_guest_room(): void
    {
        config(['sedona.midnight_promo_enabled' => false]);
        foreach (Room::where('is_staff_quarters', false)->get() as $room) {
            $data = ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => 'promo', 'guest_name' => 'Disabled promo'];
            $this->post(route('checkin.store'), $data + ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('rate_tier');
            $this->post(route('bookings.store'), $data + ['arrival_at' => '2026-10-15 20:00:00'])->assertSessionHasErrors('rate_tier');
        }
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('folios', 0);
        foreach (['dashboard', 'bookings.index'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $html = new DOMDocument;
            @$html->loadHTML($response->getContent());
            $this->assertSame(0, (new DOMXPath($html))->query('//select[@name="rate_tier"]/option[@value="promo"]')->length);
        }
    }

    public function test_an_old_promo_reservation_cannot_bypass_the_pause_with_a_different_submitted_tier(): void
    {
        config(['sedona.midnight_promo_enabled' => false]);
        $room = Room::where('number', '6')->firstOrFail();
        $booking = Booking::create(['room_id' => $room->id, 'user_id' => auth()->id(), 'guest_name' => 'Old promo booking',
            'headcount' => 2, 'rate_tier' => 'promo', 'arrival_at' => now(), 'departure_at' => now()->addHours(12), 'status' => 'scheduled']);

        $this->post(route('checkin.store'), ['room_id' => $room->id, 'booking_id' => $booking->id, 'headcount' => 2,
            'rate_tier' => '3h', 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('rate_tier');

        $this->assertDatabaseCount('folios', 0);
        $this->assertSame('scheduled', $booking->fresh()->status);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_existing_promo_stays_and_their_original_discounts_remain_billable(): void
    {
        config(['sedona.midnight_promo_enabled' => false]);
        $room = Room::where('number', '6')->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'user_id' => auth()->id(), 'guest_id' => Guest::create(['name' => 'Existing promo guest'])->id,
            'transaction_id' => Folio::generateTransactionId(), 'status' => 'active', 'rate_tier' => 'promo', 'room_charge' => 995,
            'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(12), 'discount_type' => 'DC']);
        $bill = app(BillingService::class)->calculate($folio);

        $this->assertEquals(995, $bill['roomRate']);
        $this->assertEquals(40, $bill['totalDiscount']);
        $this->assertEquals(955, $bill['finalBalanceToPay']);
    }

    public function test_promo_is_enabled_by_default_and_available_on_check_in_and_booking_forms(): void
    {
        $this->assertTrue(config('sedona.midnight_promo_enabled'));

        foreach (['dashboard', 'bookings.index'] as $route) {
            $response = $this->get(route($route));
            $html = new DOMDocument;
            @$html->loadHTML($response->getContent());
            $this->assertSame(1, (new DOMXPath($html))->query('//select[@name="rate_tier"]/option[@value="promo"]')->length);
        }
        foreach (['Classic Room' => 995, 'Premium Room' => 1055, 'VIP Suite Room' => 1155] as $type => $rate) {
            $room = Room::where('type', $type)->where('is_staff_quarters', false)->firstOrFail();
            $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2,
                'rate_tier' => 'promo', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
            $folio = Folio::where('room_id', $room->id)->sole();
            $this->assertSame('promo', $folio->rate_tier);
            $this->assertSame(number_format($rate, 2, '.', ''), $folio->room_charge);
            $this->assertSame('2026-10-15 06:00:00', $folio->expected_checkout_at->toDateTimeString());
        }
    }

    public function test_promo_reservations_can_be_created_and_checked_in_again(): void
    {
        $room = Room::where('number', '6')->firstOrFail();

        $this->post(route('bookings.store'), ['room_id' => $room->id, 'guest_name' => 'Promo guest',
            'headcount' => 2, 'rate_tier' => 'promo', 'arrival_at' => now()->toDateTimeString()])->assertSessionHasNoErrors();
        $booking = Booking::where('room_id', $room->id)->sole();
        $this->post(route('checkin.store'), ['room_id' => $room->id, 'booking_id' => $booking->id,
            'headcount' => 2, 'rate_tier' => 'promo', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['booking_id' => $booking->id, 'rate_tier' => 'promo', 'room_charge' => 995]);
        $this->assertSame('checked_in', $booking->fresh()->status);
    }
}
