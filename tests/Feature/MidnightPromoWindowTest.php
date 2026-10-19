<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Room;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MidnightPromoWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-15 20:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail());
    }

    #[TestWith(['2026-10-15 20:00:00'])]
    #[TestWith(['2026-10-15 23:15:37'])]
    #[TestWith(['2026-10-16 00:00:00'])]
    #[TestWith(['2026-10-16 05:59:59'])]
    public function test_every_eligible_arrival_ends_at_six_am_and_keeps_the_full_grace(string $arrival): void
    {
        $this->travelTo($arrival);
        $folio = $this->checkIn();

        $this->assertSame('2026-10-16 06:00:00', $folio->expected_checkout_at->toDateTimeString());
        $bill = app(BillingService::class);
        $this->assertSame(0, $bill->calculate($folio, Carbon::parse('2026-10-16 06:15:00'))['excessHours']);
        $this->assertSame(1, $bill->calculate($folio, Carbon::parse('2026-10-16 06:15:01'))['excessHours']);
        $this->assertSame(0, $bill->calculate($folio, Carbon::parse('2026-10-16 06:15:01'), false)['excessHours']);
        $this->get(route('dashboard'))->assertSee('data-checkout-time="2026-10-16T06:00:00+08:00"', false);
        $this->get(route('folios.receipt', $folio))->assertSee('Midnight promo (6 AM checkout)')->assertSee('995.00');
    }

    #[TestWith(['2026-10-15 19:59:59'])]
    #[TestWith(['2026-10-16 06:00:00'])]
    #[TestWith(['2026-10-16 12:00:00'])]
    public function test_daytime_promo_check_ins_and_reservations_are_rejected(string $arrival): void
    {
        $this->travelTo($arrival);
        $room = Room::where('number', '6')->firstOrFail();
        $data = ['room_id' => $room->id, 'guest_name' => 'Promo guest', 'headcount' => 2, 'rate_tier' => 'promo'];

        $this->post(route('checkin.store'), $data + ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('rate_tier');
        $this->post(route('bookings.store'), $data + ['arrival_at' => $arrival])->assertSessionHasErrors('arrival_at');

        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_reservations_end_at_six_without_blocking_a_later_regular_booking(): void
    {
        $room = Room::where('number', '6')->firstOrFail();
        $data = ['room_id' => $room->id, 'guest_name' => 'Reserved guest', 'headcount' => 2];

        $this->post(route('bookings.store'), $data + ['rate_tier' => '3h', 'arrival_at' => '2026-10-16 06:30:00'])->assertSessionHasNoErrors();
        $this->post(route('bookings.store'), $data + ['rate_tier' => 'promo', 'arrival_at' => '2026-10-15 23:30:00'])->assertSessionHasNoErrors();

        $booking = Booking::where('rate_tier', 'promo')->sole();
        $this->assertSame('2026-10-16 06:00:00', $booking->departure_at->toDateTimeString());
    }

    public function test_extensions_and_transfers_keep_the_six_am_anchor(): void
    {
        $this->travelTo('2026-10-15 23:30:00');
        $folio = $this->checkIn();
        $target = Room::where('type', 'Premium Room')->where('is_staff_quarters', false)->firstOrFail();

        $this->post(route('folios.extend', $folio), ['hours' => 1, 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('folios.transfer', $folio), ['target_room_id' => $target->id])->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertSame('2026-10-16 07:00:00', $folio->expected_checkout_at->toDateTimeString());
        $this->assertSame('1055.00', $folio->room_charge);
        $bill = app(BillingService::class);
        $this->assertSame(0, $bill->calculate($folio, Carbon::parse('2026-10-16 07:15:00'))['excessHours']);
        $this->assertSame(1, $bill->calculate($folio, Carbon::parse('2026-10-16 07:15:01'))['excessHours']);
    }

    public function test_admin_correction_keeps_the_same_fixed_checkout_instead_of_adding_twelve_hours(): void
    {
        $this->travelTo('2026-10-15 23:30:00');
        $folio = $this->checkIn();
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.check-in-times.update', $folio), [
            'checked_in_at' => '2026-10-15T20:00:00+08:00', 'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(),
            'reason' => 'Correct RA declaration time', 'operation_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-16 06:00:00', $folio->fresh()->expected_checkout_at->toDateTimeString());
        $this->assertSame('2026-10-15 20:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
    }

    public function test_cutoff_uses_manila_time_and_does_not_mutate_the_arrival(): void
    {
        $room = Room::where('number', '6')->firstOrFail();
        $arrival = CarbonImmutable::parse('2026-10-15 15:30:00', 'UTC');

        $checkout = $room->getCheckoutForTier('promo', $arrival);

        $this->assertSame('2026-10-15 22:00:00', $checkout->toDateTimeString());
        $this->assertSame('2026-10-15 15:30:00', $arrival->toDateTimeString());
    }

    public function test_data_correction_updates_active_stays_and_scheduled_bookings_but_preserves_settled_receipts_and_staff(): void
    {
        $folio = $this->checkIn();
        $folio->update(['expected_checkout_at' => '2026-10-16 08:00:00', 'extra_hours' => 1]);
        $settled = $folio->replicate();
        $settled->fill(['transaction_id' => Folio::generateTransactionId(), 'status' => 'checked_out', 'checked_out_at' => '2026-10-16 07:00:00', 'expected_checkout_at' => '2026-10-16 08:00:00']);
        $settled->save();
        $staff = Room::where('is_staff_quarters', true)->firstOrFail()->getOrCreateStaffFolio();
        $staffCheckout = $staff->expected_checkout_at->toDateTimeString();
        $booking = Booking::create(['room_id' => $folio->room_id, 'user_id' => auth()->id(), 'guest_name' => 'Future promo',
            'headcount' => 2, 'rate_tier' => 'promo', 'arrival_at' => '2026-10-16 23:00:00', 'departure_at' => '2026-10-17 11:00:00', 'status' => 'scheduled']);

        $migration = require database_path('migrations/2026_10_15_204050_correct_midnight_promo_checkout_times.php');
        $migration->up();

        $this->assertSame('2026-10-16 07:00:00', $folio->fresh()->expected_checkout_at->toDateTimeString());
        $this->assertSame('2026-10-17 06:00:00', $booking->fresh()->departure_at->toDateTimeString());
        $this->assertSame('2026-10-16 08:00:00', $settled->fresh()->expected_checkout_at->toDateTimeString());
        $this->assertSame($staffCheckout, $staff->fresh()->expected_checkout_at->toDateTimeString());
    }

    private function checkIn(): Folio
    {
        $room = Room::where('number', '6')->firstOrFail();
        $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2,
            'rate_tier' => 'promo', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Folio::where('room_id', $room->id)->sole();
    }
}
