<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OpenTimeStayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-16 10:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail());
        Shift::create(['opened_by' => auth()->id(), 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now(), 'opening_float' => 100]);
    }

    #[TestWith(['6', 395, 40])]
    #[TestWith(['13', 495, 50])]
    #[TestWith(['1', 695, 65])]
    public function test_open_time_uses_the_room_three_hour_rate_and_original_discount_with_one_grace_period(string $number, int $rate, int $discount): void
    {
        $folio = $this->checkIn($number);
        $this->post(route('folios.discount', $folio), ['discount_type' => 'dc', 'discount_id_ref' => 'DC-OPEN'])->assertSessionHasNoErrors();
        $folio->refresh();

        $this->assertTrue($folio->isOpenTime());
        $this->assertSame('3h', $folio->rate_tier);
        $this->assertSame(number_format($rate, 2, '.', ''), $folio->room_charge);
        $this->assertSame('2026-10-16 13:00:00', $folio->expected_checkout_at->toDateTimeString());
        foreach (['13:15:00' => 0, '13:15:01' => 1, '14:15:00' => 1, '14:15:01' => 2, '15:10:00' => 2] as $time => $hours) {
            $bill = app(BillingService::class)->calculate($folio, Carbon::parse('2026-10-16 '.$time));
            $this->assertSame($hours, $bill['excessHours']);
            $this->assertSame($rate + $hours * 130 - $discount, (int) $bill['finalBalanceToPay']);
        }
    }

    #[TestWith(['pau@sedonapms.com', false])]
    #[TestWith(['pau@sedonapms.com', true])]
    #[TestWith(['admin1@sedonapms.com', false])]
    #[TestWith(['owner@sedonapms.com', true])]
    public function test_open_time_is_always_selectable_in_the_actual_checkin_dropdown(string $email, bool $promoEnabled): void
    {
        config(['sedona.midnight_promo_enabled' => $promoEnabled]);
        $response = $this->actingAs(User::where('email', $email)->firstOrFail())->get(route('dashboard'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $select = $xpath->query('//form[@id="guestCheckInForm"]//select[@name="rate_tier" and @id="checkInTierSelect"]');
        $this->assertSame(1, $select->length);
        $option = $xpath->query('//select[@id="checkInTierSelect"]/option[@value="open" and not(@disabled) and not(@hidden)]');
        $this->assertSame(1, $option->length);
        $this->assertStringContainsString('Open Time', $option->item(0)->textContent);
    }

    public function test_open_check_in_retries_create_one_stay_and_dashboard_exposes_live_timer_data_without_marking_it_late(): void
    {
        $room = Room::where('number', '6')->firstOrFail();
        $data = ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => 'open', 'operation_key' => (string) Str::uuid()];
        $this->post(route('checkin.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('checkin.store'), $data)->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->sole();
        $this->travelTo('2026-10-16 15:10:00');

        $this->assertFalse($folio->isOverdue());
        $this->get(route('dashboard'))->assertSee('data-stay-mode="open"', false)->assertSee('data-base-rate="395.00"', false)
            ->assertSee('data-checkin-time="2026-10-16T10:00:00+08:00"', false)->assertSee('OPEN TIME');
        $this->actingAs(User::where('email', 'owner@sedonapms.com')->firstOrFail())->get(route('dashboard'))
            ->assertViewHas('boardCounts', fn (array $counts): bool => $counts['late'] === 0 && $counts['almost'] === 0)
            ->assertSee('data-stay-mode="open"', false);
        $this->assertSame(1, Folio::where('room_id', $room->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'folio.checked_in')->count());
    }

    public function test_checkout_freezes_open_time_charges_and_prints_the_mode_and_actual_duration(): void
    {
        $folio = $this->checkIn();
        $this->travelTo('2026-10-16 15:10:00');
        $this->get(route('checkout.process', $folio))->assertSee('Open Time (3-hour base)')->assertViewHas('finalBalanceToPay', 655);

        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 1000])->assertSessionHasNoErrors();
        $this->travelTo('2026-10-17 10:00:00');

        $folio->refresh();
        $this->assertSame('655.00', $folio->net_total);
        $this->assertSame('345.00', $folio->change_due);
        $this->assertSame('available', $folio->room->status);
        foreach (['folios.receipt', 'folios.billing'] as $route) {
            $this->get(route($route, $folio))->assertSee('Open Time (3-hour base)')->assertSee('5h 10m')->assertSee('Additional Hours')->assertSee('655.00');
        }
        $this->assertSame(655, (int) app(BillingService::class)->calculate($folio)['finalBalanceToPay']);
        $this->reserve($folio->room);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_open_time_blocks_future_room_reservations_until_checkout(): void
    {
        $folio = $this->checkIn();
        $this->post(route('bookings.store'), ['room_id' => $folio->room_id, 'guest_name' => 'Future guest', 'headcount' => 2,
            'rate_tier' => '3h', 'arrival_at' => '2026-10-18 10:00:00'])->assertSessionHasErrors('arrival_at');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_upcoming_reservation_blocks_open_time_check_in_but_allows_a_fixed_package(): void
    {
        $room = Room::where('number', '6')->firstOrFail();
        $this->reserve($room);

        $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => 'open'])->assertSessionHasErrors('room_id');
        $this->assertDatabaseCount('folios', 0);
        $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'])->assertSessionHasNoErrors();
        $this->assertFalse(Folio::where('room_id', $room->id)->sole()->isOpenTime());
    }

    public function test_open_time_transfer_preserves_arrival_and_uses_destination_three_hour_rate(): void
    {
        $folio = $this->checkIn();
        $target = Room::where('number', '13')->firstOrFail();

        $this->post(route('folios.transfer', $folio), ['target_room_id' => $target->id])->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertTrue($folio->isOpenTime());
        $this->assertSame('495.00', $folio->room_charge);
        $this->assertSame('2026-10-16 13:00:00', $folio->expected_checkout_at->toDateTimeString());
        $this->assertSame(755, (int) app(BillingService::class)->calculate($folio, Carbon::parse('2026-10-16 15:10:00'))['finalBalanceToPay']);
    }

    public function test_open_time_transfer_cannot_move_into_a_room_with_a_future_reservation(): void
    {
        $folio = $this->checkIn();
        $target = Room::where('number', '13')->firstOrFail();
        $this->reserve($target);

        $this->post(route('folios.transfer', $folio), ['target_room_id' => $target->id])->assertSessionHasErrors('room_id');

        $this->assertSame($folio->room_id, $folio->fresh()->room_id);
        $this->assertSame('available', $target->fresh()->status);
    }

    #[TestWith(['12h', 1195, '2026-10-16 22:00:00'])]
    #[TestWith(['24h', 2100, '2026-10-17 10:00:00'])]
    public function test_conversion_switches_open_time_to_a_fixed_package_from_original_arrival(string $tier, int $rate, string $checkout): void
    {
        $folio = $this->checkIn();
        $this->post(route('folios.extend', $folio), ['hours' => 1, 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('hours');
        $this->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => $options[$folio->id]['openTime'] === true);

        $this->post(route('folios.convert', $folio), ['rate_tier' => $tier, 'reason' => 'Guest requested fixed package', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->assertFalse($folio->fresh()->isOpenTime());
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'stay_mode' => 'fixed', 'room_charge' => $rate, 'rate_tier' => $tier, 'expected_checkout_at' => $checkout, 'extra_hours' => 0]);
    }

    public function test_only_admin_can_correct_open_time_arrival_and_billing_reanchors_to_the_corrected_time(): void
    {
        $folio = $this->checkIn();
        $data = ['checked_in_at' => '2026-10-16T09:00:00+08:00', 'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(),
            'reason' => 'RA declared guest at nine', 'operation_key' => (string) Str::uuid()];
        $this->post(route('admin.check-in-times.update', $folio), $data)->assertForbidden();

        $this->actingAs(User::role('admin')->firstOrFail())->post(route('admin.check-in-times.update', $folio), $data)->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertTrue($folio->isOpenTime());
        $this->assertSame('2026-10-16 12:00:00', $folio->expected_checkout_at->toDateTimeString());
        $this->assertSame(525, (int) app(BillingService::class)->calculate($folio, Carbon::parse('2026-10-16 12:15:01'))['finalBalanceToPay']);
    }

    private function checkIn(string $number = '6'): Folio
    {
        $room = Room::where('number', $number)->firstOrFail();
        $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => 'open', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Folio::where('room_id', $room->id)->sole();
    }

    private function reserve(Room $room): void
    {
        $this->post(route('bookings.store'), ['room_id' => $room->id, 'guest_name' => 'Future reservation', 'headcount' => 2,
            'rate_tier' => '3h', 'arrival_at' => '2026-10-17 10:00:00'])->assertSessionHasNoErrors();
    }
}
