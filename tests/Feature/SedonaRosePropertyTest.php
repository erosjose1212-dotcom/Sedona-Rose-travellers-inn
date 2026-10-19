<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SedonaRoseRoomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SedonaRosePropertyTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['1', 'Classic Room', 395.0])]
    #[TestWith(['8', 'Classic Room', 395.0])]
    #[TestWith(['9', 'Premium Room', 495.0])]
    #[TestWith(['12', 'Premium Room', 495.0])]
    #[TestWith(['20', 'Premium Room', 495.0])]
    public function test_default_property_seed_has_the_twenty_requested_guest_rooms(string $number, string $type, float $baseRate): void
    {
        config(['sedona.property_code' => 'rose']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(range(1, 20), Room::orderByNumber()->pluck('number')->map(fn (string $value): int => (int) $value)->all());
        $this->assertSame(8, Room::where('type', 'Classic Room')->count());
        $this->assertSame(12, Room::where('type', 'Premium Room')->count());
        $room = Room::where('number', $number)->firstOrFail();
        $this->assertSame($type, $room->type);
        $this->assertFalse($room->is_staff_quarters);
        $this->assertSame($baseRate, $room->getRateForTier('3h'));
    }

    #[TestWith(['1', 695, 'Classic Room'])]
    #[TestWith(['12', 0, 'Premium Room'])]
    public function test_reconfiguration_preserves_existing_stays_and_saved_rates(string $number, int $charge, string $type): void
    {
        $this->seed(DatabaseSeeder::class);
        $room = Room::where('number', $number)->firstOrFail();
        if (! $room->is_staff_quarters) {
            $room->update(['status' => 'occupied']);
        }
        $folio = Folio::create(['room_id' => $room->id, 'user_id' => User::firstOrFail()->id,
            'guest_id' => Guest::create(['name' => 'Copied guest'])->id, 'rate_tier' => '3h',
            'room_charge' => $charge, 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'status' => 'active']);
        $reference = $folio->transaction_id;

        $this->seed(SedonaRoseRoomSeeder::class);

        $this->assertSame($room->id, $folio->fresh()->room_id);
        $this->assertSame($reference, $folio->fresh()->transaction_id);
        $this->assertSame((float) $charge, (float) $folio->fresh()->room_charge);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame($type, $room->fresh()->type);
        $room->update(['base_rate_3h' => 410]);
        $this->seed(SedonaRoseRoomSeeder::class);
        $this->assertSame(410.0, $room->fresh()->getRateForTier('3h'));
    }

    public function test_out_of_range_rooms_with_history_are_never_deleted(): void
    {
        $this->seed(DatabaseSeeder::class);
        $room = Room::where('number', '32')->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'user_id' => User::firstOrFail()->id,
            'guest_id' => Guest::create(['name' => 'Historical guest'])->id, 'rate_tier' => '3h',
            'room_charge' => 395, 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'status' => 'checked_out']);

        try {
            $this->seed(SedonaRoseRoomSeeder::class);
            $this->fail('A room with history must not be removed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('has history', $exception->getMessage());
        }

        $this->assertModelExists($room);
        $this->assertModelExists($folio);
        $this->assertDatabaseCount('rooms', 32);
    }

    public function test_login_lobby_and_billing_use_rose_branding_and_address(): void
    {
        config(['sedona.property_code' => 'rose']);
        $this->seed(DatabaseSeeder::class);
        $this->get(route('login'))->assertSee('SEDONA ROSE')->assertDontSee('SEDONA COURT');
        $this->get(route('kiosk.view'))->assertSee('B.S. Aquino Avenue, St, San Roque Hinukay, Baliwag, Bulacan')
            ->assertViewHas('totalRooms', 20)->assertDontSee('VIP SUITE ROOM');
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '12')->firstOrFail();
        $folio = Folio::create(['room_id' => $room->id, 'user_id' => $cashier->id,
            'guest_id' => Guest::create(['name' => 'Rose guest'])->id, 'rate_tier' => '3h',
            'room_charge' => 495, 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'status' => 'active']);

        $this->actingAs($cashier)->get(route('folios.billing', $folio))->assertSee('SEDONA ROSE TRAVELLERS INN')
            ->assertSee('B.S. Aquino Avenue, St, San Roque Hinukay, Baliwag, Bulacan')->assertDontSee('San Rafael');
    }

    public function test_room_twelve_can_check_in_a_guest_at_the_premium_rate(): void
    {
        config(['sedona.property_code' => 'rose']);
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '12')->firstOrFail();

        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id,
            'guest_name' => 'Premium guest', 'headcount' => 2, 'rate_tier' => '12h'])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['room_id' => $room->id, 'room_charge' => 1295, 'rate_tier' => '12h', 'status' => 'active']);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertFalse($room->fresh()->is_staff_quarters);
    }
}
