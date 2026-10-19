<?php

namespace Tests\Feature;

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

class LobbyKioskDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-17 23:00:00');
    }

    #[TestWith(['cashier'])]
    #[TestWith(['front_desk'])]
    public function test_cashier_sidebar_links_to_the_read_only_kiosk_in_a_new_tab(string $role): void
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('view_dashboard', 'web'));
        $this->actingAs($actor)->get(route('dashboard'))->assertOk()->assertSee('Lobby Kiosk Display')
            ->assertSee('href="'.route('kiosk.view').'"', false);
        $this->get(route('kiosk.view'))->assertOk()->assertDontSee('Complete Official Check-Out');
    }

    public function test_occupied_blocks_show_hotel_checkin_and_next_day_checkout_without_guest_details(): void
    {
        $stay = $this->stay('10');
        $stay->update(['checked_in_at' => '2026-10-17 23:00:00', 'expected_checkout_at' => '2026-10-18 11:00:00', 'rate_tier' => '12h']);
        $response = $this->get(route('kiosk.view'))->assertOk()
            ->assertSee('Check-in')->assertSee('Oct 17, 11:00 PM')->assertSee('Expected checkout')->assertSee('Oct 18, 11:00 AM')
            ->assertDontSee('Private Guest Name')->assertDontSee($stay->transaction_id);
        $rooms = $response->viewData('rooms');
        $this->assertTrue($rooms->first()->relationLoaded('activeFolio'));
        $this->assertFalse($rooms->first()->activeFolio->relationLoaded('guest'));
        $this->assertArrayNotHasKey('guest_id', $rooms->first()->activeFolio->getAttributes());
        $this->assertArrayNotHasKey('room_charge', $rooms->first()->activeFolio->getAttributes());
    }

    public function test_open_time_labels_the_base_end_instead_of_promising_a_fixed_checkout(): void
    {
        $stay = $this->stay('11');
        $stay->update(['stay_mode' => 'open']);
        $this->get(route('kiosk.view'))->assertOk()->assertSee('Open Time · Base ends')->assertSee('Oct 18, 02:00 AM')->assertDontSee('Expected checkout');
    }

    public function test_available_rooms_and_staff_quarters_do_not_display_stay_times(): void
    {
        $stay = $this->stay('12');
        $stay->room->update(['is_staff_quarters' => true]);
        $this->room('10', 'available');
        $this->get(route('kiosk.view'))->assertOk()->assertSee('AVAILABLE')->assertDontSee('Check-in')->assertDontSee('Expected checkout')->assertDontSee('Oct 17, 11:00 PM');
    }

    public function test_rooms_without_an_active_stay_do_not_show_historical_checkin_times(): void
    {
        $stay = $this->stay('10');
        $stay->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $this->get(route('kiosk.view'))->assertOk()->assertSee('Check-in')->assertSee('Expected checkout')->assertDontSee('Oct 17, 11:00 PM');
    }

    public function test_saved_corrections_and_extensions_are_reflected_on_refresh(): void
    {
        $stay = $this->stay('10');
        $this->get(route('kiosk.view'))->assertOk()->assertSee('Oct 18, 02:00 AM');
        $stay->update(['checked_in_at' => '2026-10-17 22:00:00', 'expected_checkout_at' => '2026-10-18 04:00:00', 'extra_hours' => 3]);
        $this->get(route('kiosk.view'))->assertOk()->assertSee('Oct 17, 10:00 PM')->assertSee('Oct 18, 04:00 AM')->assertDontSee('Oct 18, 02:00 AM');
    }

    public function test_kiosk_uses_manila_time_when_the_database_uses_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $stay = $this->stay('10');
            $stay->update(['checked_in_at' => '2026-10-17 15:00:00', 'expected_checkout_at' => '2026-10-17 18:00:00']);
            $this->get(route('kiosk.view'))->assertOk()->assertSee('Oct 17, 11:00 PM')->assertSee('Oct 18, 02:00 AM');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[TestWith(['checked_out'])]
    #[TestWith(['voided'])]
    public function test_transferred_stay_is_visible_when_destination_has_a_newer_inactive_stay(string $status): void
    {
        $transferred = $this->stay('1');
        $previous = $this->stay('10');
        $previous->update(['status' => $status, 'checked_in_at' => '2026-10-17 19:00:00', 'checked_out_at' => '2026-10-17 22:00:00']);
        $previous->room->update(['status' => 'available']);
        $cashier = User::factory()->create()->assignRole(Role::findOrCreate('cashier', 'web'));
        foreach (['manage_folios', 'view_dashboard'] as $permission) {
            $cashier->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($cashier)->post(route('folios.transfer', $transferred), ['target_room_id' => $previous->room_id])->assertSessionHasNoErrors();

        $response = $this->get(route('kiosk.view'))->assertOk()->assertSee('Oct 17, 11:00 PM')->assertSee('Oct 18, 02:00 AM');
        $destination = $response->viewData('rooms')->firstWhere('number', '10');
        $this->assertSame($transferred->id, $destination->activeFolio?->id);
        $this->assertSame($transferred->id, $destination->fresh()->activeFolio?->id);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('activeFolios', fn ($folios): bool => $folios->contains('id', $transferred->id));
        $transferred->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $destination->update(['status' => 'available']);
        $this->get(route('kiosk.view'))->assertOk()->assertDontSee('Oct 17, 11:00 PM')->assertDontSee('Expected checkout');
    }

    public function test_loading_more_occupied_rooms_does_not_add_one_query_per_room(): void
    {
        $this->stay('1');
        DB::enableQueryLog();
        $this->get(route('kiosk.view'))->assertOk();
        $oneRoomQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(2, 32) as $number) {
            $this->stay((string) $number);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('kiosk.view'))->assertOk();
        $manyRoomQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($oneRoomQueries, $manyRoomQueries);
    }

    private function room(string $number, string $status = 'occupied'): Room
    {
        return Room::create(['number' => $number, 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => $status, 'base_rate_3h' => 395]);
    }

    private function stay(string $number): Folio
    {
        return Folio::create(['room_id' => $this->room($number)->id, 'guest_id' => Guest::create(['name' => 'Private Guest Name', 'headcount' => 2])->id,
            'user_id' => User::factory()->create()->id, 'transaction_id' => (string) Str::uuid(), 'status' => 'active', 'rate_tier' => '3h', 'room_charge' => 395,
            'checked_in_at' => '2026-10-17 23:00:00', 'expected_checkout_at' => '2026-10-18 02:00:00']);
    }
}
