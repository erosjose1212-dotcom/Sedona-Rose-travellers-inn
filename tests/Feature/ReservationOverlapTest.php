<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReservationOverlapTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['2026-10-09 06:00:00'])]
    #[TestWith(['2026-10-09 12:00:00'])]
    public function test_reservation_accepts_an_adjacent_slot(string $arrival): void
    {
        [$cashier, $room] = $this->scheduledReservation();

        $this->actingAs($cashier)->post(route('bookings.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Adjacent reservation',
            'headcount' => 2,
            'rate_tier' => '3h',
            'arrival_at' => $arrival,
        ])->assertRedirect(route('bookings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseHas('bookings', ['guest_name' => 'Adjacent reservation', 'arrival_at' => $arrival, 'status' => 'scheduled']);
    }

    #[TestWith(['2026-10-09 06:00:01', '3h'])]
    #[TestWith(['2026-10-09 11:59:59', '3h'])]
    #[TestWith(['2026-10-09 09:00:00', '3h'])]
    #[TestWith(['2026-10-09 08:00:00', '6h'])]
    public function test_reservation_rejects_actual_overlap_without_creating_a_record(string $arrival, string $tier): void
    {
        [$cashier, $room] = $this->scheduledReservation();

        $this->actingAs($cashier)->post(route('bookings.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Overlapping reservation',
            'headcount' => 2,
            'rate_tier' => $tier,
            'arrival_at' => $arrival,
        ])->assertSessionHasErrors(['arrival_at' => 'This room is already reserved for the selected date/time.']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseMissing('bookings', ['guest_name' => 'Overlapping reservation']);
    }

    public function test_cancelled_reservation_does_not_block_the_same_slot(): void
    {
        [$cashier, $room] = $this->scheduledReservation();
        Booking::firstOrFail()->update(['status' => 'cancelled']);

        $this->actingAs($cashier)->post(route('bookings.store'), [
            'room_id' => $room->id,
            'guest_name' => 'Replacement reservation',
            'headcount' => 2,
            'rate_tier' => '3h',
            'arrival_at' => '2026-10-09 09:00:00',
        ])->assertRedirect(route('bookings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bookings', ['guest_name' => 'Replacement reservation', 'arrival_at' => '2026-10-09 09:00:00', 'status' => 'scheduled']);
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_reservation_rejects_a_slot_overlapping_a_guest_already_in_house(): void
    {
        [$cashier, $room] = $this->scheduledReservation();
        $this->travelTo('2026-10-09 10:00:00');
        Booking::firstOrFail()->update(['status' => 'checked_in']);
        $room->update(['status' => 'occupied']);
        Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'In-house guest'])->id,
            'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h',
            'checked_in_at' => '2026-10-09 09:00:00', 'expected_checkout_at' => '2026-10-09 12:00:00', 'status' => 'active']);

        $this->actingAs($cashier)->post(route('bookings.store'), ['room_id' => $room->id, 'guest_name' => 'Overbooked guest',
            'headcount' => 2, 'rate_tier' => '3h', 'arrival_at' => '2026-10-09 10:00:00'])->assertSessionHasErrors('arrival_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_reservation_accepts_a_slot_after_the_current_guest_is_due_to_leave(): void
    {
        [$cashier, $room] = $this->scheduledReservation();
        $this->travelTo('2026-10-09 10:00:00');
        Booking::firstOrFail()->update(['status' => 'checked_in']);
        $room->update(['status' => 'occupied']);
        Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'In-house guest'])->id,
            'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h',
            'checked_in_at' => '2026-10-09 09:00:00', 'expected_checkout_at' => '2026-10-09 12:00:00', 'status' => 'active']);

        $this->actingAs($cashier)->post(route('bookings.store'), ['room_id' => $room->id, 'guest_name' => 'Next guest',
            'headcount' => 2, 'rate_tier' => '3h', 'arrival_at' => '2026-10-09 12:00:00'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_pagination_keeps_the_selected_reservation_status(): void
    {
        [$cashier, $room] = $this->scheduledReservation();
        for ($index = 0; $index < 16; $index++) {
            Booking::create(['room_id' => $room->id, 'user_id' => $cashier->id, 'guest_name' => 'Cancelled reservation '.$index,
                'headcount' => 1, 'rate_tier' => '3h', 'arrival_at' => '2026-10-09 13:00:00', 'departure_at' => '2026-10-09 16:00:00', 'status' => 'cancelled']);
        }
        $response = $this->actingAs($cashier)->get(route('bookings.index', ['status' => 'all']))->assertOk();
        $nextPage = $response->viewData('bookings')->url(2);

        $this->get($nextPage)->assertOk()->assertViewHas('status', 'all')
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 17 && $bookings->count() === 2);
    }

    public function test_walk_in_cannot_take_a_reserved_room_during_the_booked_slot(): void
    {
        [$cashier, $room] = $this->scheduledReservation();
        $this->travelTo('2026-10-09 08:00:00');

        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id,
            'guest_name' => 'Walk-in guest', 'headcount' => 2, 'rate_tier' => '3h'])
            ->assertSessionHasErrors('room_id');

        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('guests', 0);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertSame('scheduled', Booking::firstOrFail()->status);
    }

    #[TestWith([false, '2026-10-09 06:00:00'])]
    #[TestWith([true, '2026-10-09 09:00:00'])]
    public function test_check_in_allows_an_adjacent_walk_in_or_its_own_reservation(bool $reserved, string $arrival): void
    {
        [$cashier, $room] = $this->scheduledReservation();
        $this->travelTo($arrival);
        $data = ['room_id' => $room->id, 'guest_name' => 'Valid check-in', 'headcount' => 2, 'rate_tier' => '3h'];
        if ($reserved) {
            $data['booking_id'] = Booking::firstOrFail()->id;
        }

        $this->actingAs($cashier)->post(route('checkin.store'), $data)
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('folios', 1);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame($reserved ? 'checked_in' : 'scheduled', Booking::firstOrFail()->status);
    }

    #[TestWith(['extend'])]
    #[TestWith(['convert'])]
    #[TestWith(['transfer'])]
    public function test_stay_changes_cannot_displace_an_existing_reservation(string $action): void
    {
        [$cashier, $reservedRoom] = $this->scheduledReservation();
        $this->travelTo('2026-10-09 08:00:00');
        $reservedRoom->update(['base_rate_3h' => 395, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100]);
        $room = $action === 'transfer'
            ? Room::create(['number' => '2', 'floor' => 1, 'name' => 'Occupied room', 'type' => 'Classic Room', 'status' => 'occupied'])
            : $reservedRoom;
        $room->update(['status' => 'occupied']);
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Current guest'])->id,
            'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(),
            'rate_tier' => $action === 'convert' ? '12h' : '3h', 'room_charge' => $action === 'convert' ? 1195 : 395,
            'checked_in_at' => match ($action) {
                'convert' => '2026-10-08 20:00:00', 'transfer' => '2026-10-09 07:00:00', default => '2026-10-09 06:00:00',
            },
            'expected_checkout_at' => match ($action) {
                'convert' => '2026-10-09 08:00:00', 'transfer' => '2026-10-09 10:00:00', default => '2026-10-09 09:00:00',
            }, 'status' => 'active']);
        $before = $folio->fresh()->attributesToArray();
        $data = match ($action) {
            'extend' => ['hours' => 1],
            'convert' => ['rate_tier' => '24h', 'reason' => 'Guest requests conversion'],
            'transfer' => ['target_room_id' => $reservedRoom->id],
        };

        $this->actingAs($cashier)->post(route('folios.'.$action, $folio), $data + ['operation_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('room_id');

        $this->assertSame($before, $folio->fresh()->attributesToArray());
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame($action === 'transfer' ? 'available' : 'occupied', $reservedRoom->fresh()->status);
        $this->assertSame('scheduled', Booking::firstOrFail()->status);
    }

    /** @return array{User, Room} */
    private function scheduledReservation(): array
    {
        $this->travelTo('2026-10-08 12:00:00');
        $cashier = User::factory()->create();
        $cashier->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $room = Room::create(['number' => '1', 'floor' => 1, 'name' => 'Reservation room', 'type' => 'Classic Room', 'status' => 'available']);
        Booking::create([
            'room_id' => $room->id, 'user_id' => $cashier->id, 'guest_name' => 'Existing reservation',
            'headcount' => 2, 'rate_tier' => '3h', 'arrival_at' => '2026-10-09 09:00:00',
            'departure_at' => '2026-10-09 12:00:00', 'status' => 'scheduled',
        ]);

        return [$cashier, $room];
    }
}
