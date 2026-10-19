<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoomNumberOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-10-10 12:00:00');
    }

    #[TestWith(['owner@sedonapms.com'])]
    #[TestWith(['pau@sedonapms.com'])]
    public function test_dashboard_rooms_and_transfer_choices_follow_numeric_order(string $email): void
    {
        $staff = User::where('email', $email)->firstOrFail();
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()
            ->assertViewHas('rooms', fn (Collection $rooms): bool => $this->numbers($rooms) === range(1, 32))
            ->assertViewHas('boardRooms', fn (Collection $rooms): bool => $this->numbers($rooms) === range(1, 32))
            ->assertViewHas('availableRoomsList', fn (Collection $rooms): bool => $this->numbers($rooms) === array_values(array_diff(range(1, 32), [12])));
    }

    public function test_filtered_dashboard_keeps_rooms_in_numeric_order(): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $this->actingAs($owner)->get(route('dashboard', ['floor' => 1]))->assertOk()
            ->assertViewHas('rooms', fn (Collection $rooms): bool => $this->numbers($rooms) === range(1, 11));
        $this->get(route('dashboard', ['btier' => 'premium']))->assertOk()
            ->assertViewHas('boardRooms', fn (Collection $rooms): bool => $this->numbers($rooms) === range(13, 26));
    }

    #[TestWith(['admin.pricing.index', 'rooms', false])]
    #[TestWith(['reports.index', 'rooms', false])]
    #[TestWith(['bookings.index', 'availableRooms', true])]
    public function test_pricing_reports_and_reservation_choices_use_numeric_room_order(string $route, string $viewVariable, bool $excludeStaff): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $expected = $excludeStaff ? array_values(array_diff(range(1, 32), [12])) : range(1, 32);
        $this->actingAs($owner)->get(route($route))->assertOk()
            ->assertViewHas($viewVariable, fn (Collection $rooms): bool => $this->numbers($rooms) === $expected);
    }

    public function test_lobby_room_list_uses_numeric_order(): void
    {
        $this->get(route('kiosk.view'))->assertOk()->assertViewHas('rooms', fn (Collection $rooms): bool => $this->numbers($rooms) === range(1, 32));
    }

    public function test_occupied_lists_and_guest_discount_editor_use_room_numbers_instead_of_creation_order(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        foreach ([20, 3, 11] as $number) {
            $room = Room::where('number', (string) $number)->firstOrFail();
            $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'guest_name' => 'Ordering Guest '.$number, 'headcount' => 1, 'rate_tier' => '3h'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->get(route('dashboard'))->assertOk()->assertViewHas('activeFolios', fn (Collection $folios): bool => $folios->map(fn (Folio $folio): int => (int) $folio->room->number)->all() === [3, 11, 12, 20]);
        $this->get(route('pos.index'))->assertOk()->assertViewHas('occupiedRooms', fn (Collection $rooms): bool => $this->numbers($rooms) === [3, 11, 12, 20]);
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $this->actingAs($owner)->get(route('admin.discounts.index'))->assertOk()->assertViewHas('folios', fn (Collection $folios): bool => $folios->map(fn (Folio $folio): int => (int) $folio->room->number)->all() === [3, 11, 20]);
    }

    /** @param Collection<int, Room> $rooms @return list<int> */
    private function numbers(Collection $rooms): array
    {
        return $rooms->map(fn (Room $room): int => (int) $room->number)->values()->all();
    }
}
