<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierStayIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-14 10:00:00');
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        $cashier->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $this->actingAs($cashier);
    }

    public function test_check_in_retry_returns_the_original_stay_without_consuming_another_number(): void
    {
        $room = $this->room();
        $payload = ['operation_key' => (string) Str::uuid(), 'room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'];
        $this->post(route('checkin.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('checkin.store'), $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseCount('folios', 1);
        $this->assertDatabaseCount('guests', 1);
        $this->assertSame('STAY-20261014-002', Folio::generateTransactionId());
    }

    public function test_check_in_key_cannot_be_reused_with_different_room_details(): void
    {
        $first = $this->room();
        $second = $this->room();
        $payload = ['operation_key' => (string) Str::uuid(), 'room_id' => $first->id, 'headcount' => 2, 'rate_tier' => '3h'];
        $this->post(route('checkin.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('checkin.store'), array_replace($payload, ['room_id' => $second->id]))->assertSessionHasErrors('operation_key');
        $this->assertDatabaseCount('folios', 1);
        $this->assertSame('available', $second->fresh()->status);
    }

    public function test_extension_retry_changes_time_and_bill_once_and_records_the_cashier(): void
    {
        $folio = $this->folio();
        $payload = ['operation_key' => (string) Str::uuid(), 'hours' => '2'];
        $this->post(route('folios.extend', $folio), $payload)->assertSessionHasNoErrors();
        $this->post(route('folios.extend', $folio), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'extra_hours' => 2, 'net_total' => 655, 'expected_checkout_at' => '2026-10-14 15:00:00']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'folio.extended')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'folio.extended', 'user_id' => auth()->id(), 'auditable_id' => $folio->id]);
    }

    public function test_extension_key_cannot_be_reused_for_a_different_number_of_hours(): void
    {
        $folio = $this->folio();
        $payload = ['operation_key' => (string) Str::uuid(), 'hours' => '2'];
        $this->post(route('folios.extend', $folio), $payload)->assertSessionHasNoErrors();
        $this->post(route('folios.extend', $folio), array_replace($payload, ['hours' => '3']))->assertSessionHasErrors('operation_key');
        $this->assertSame(2, $folio->fresh()->extra_hours);
    }

    public function test_extension_of_a_settled_stay_is_rejected_without_changing_the_bill(): void
    {
        $folio = $this->folio();
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $this->post(route('folios.extend', $folio), ['operation_key' => (string) Str::uuid(), 'hours' => '2'])->assertConflict();
        $this->assertSame(0, $folio->fresh()->extra_hours);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'folio.extended']);
    }

    #[TestWith([9])]
    #[TestWith([256])]
    public function test_check_in_and_reservations_reject_guest_counts_above_the_form_capacity(int $headcount): void
    {
        $room = $this->room();
        $data = ['room_id' => $room->id, 'headcount' => $headcount, 'guest_name' => 'Capacity test', 'rate_tier' => '3h'];

        $this->post(route('checkin.store'), $data + ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('headcount');
        $this->post(route('bookings.store'), $data + ['arrival_at' => '2026-10-15 10:00:00'])->assertSessionHasErrors('headcount');

        $this->assertDatabaseCount('guests', 0);
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_an_old_reservation_cannot_bypass_the_guest_capacity_by_submitting_a_smaller_count(): void
    {
        $room = $this->room();
        $booking = Booking::create(['room_id' => $room->id, 'user_id' => auth()->id(), 'guest_name' => 'Old reservation',
            'headcount' => 9, 'rate_tier' => '3h', 'arrival_at' => now(), 'departure_at' => now()->addHours(3), 'status' => 'scheduled']);

        $this->post(route('checkin.store'), ['room_id' => $room->id, 'booking_id' => $booking->id, 'headcount' => 2,
            'rate_tier' => '3h', 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('headcount');

        $this->assertDatabaseCount('folios', 0);
        $this->assertSame('scheduled', $booking->fresh()->status);
    }

    #[TestWith([1])]
    #[TestWith([8])]
    public function test_check_in_accepts_both_ends_of_the_guest_capacity(int $headcount): void
    {
        $room = $this->room();
        $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => $headcount,
            'rate_tier' => '3h', 'operation_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('guests', ['headcount' => $headcount]);
        $this->assertDatabaseHas('folios', ['room_id' => $room->id, 'status' => 'active']);
    }

    private function room(): Room
    {
        return Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'available', 'base_rate_3h' => 395]);
    }

    private function folio(): Folio
    {
        $room = $this->room();
        $room->update(['status' => 'occupied']);

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Cashier QA Guest'])->id, 'user_id' => auth()->id(), 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'room_charge' => 395, 'status' => 'active']);
    }
}
