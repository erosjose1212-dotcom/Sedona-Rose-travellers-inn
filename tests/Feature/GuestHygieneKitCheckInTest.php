<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\InventoryEvent;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestHygieneKitCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00:00');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_check_in_issues_one_free_kit_and_retries_do_not_deduct_twice(bool $reserved): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        $kit = $this->kit();
        $otherItem = PosItem::create(['name' => 'Water', 'category' => 'Drinks', 'price' => 25, 'stock_quantity' => 10]);
        $data = $this->checkInData($room);
        if ($reserved) {
            $booking = Booking::create(['room_id' => $room->id, 'user_id' => $cashier->id,
                'guest_name' => 'Reserved guest', 'headcount' => 2, 'rate_tier' => '3h',
                'arrival_at' => now(), 'departure_at' => now()->addHours(3), 'status' => 'scheduled']);
            $data['booking_id'] = $booking->id;
        }

        $this->actingAs($cashier)->post(route('checkin.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post(route('checkin.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        $folio = Folio::firstOrFail();
        $this->assertSame(9, $kit->fresh()->stock_quantity);
        $this->assertSame(10, $otherItem->fresh()->stock_quantity);
        $this->assertSame('395.00', $folio->net_total);
        $this->assertSame('0.00', $folio->pos_total);
        $this->assertSame([], $folio->pos_items);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('folios', 1);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $kit->id,
            'event_type' => 'guest_amenity', 'quantity_change' => -1, 'balance_after' => 9,
            'reference_id' => $folio->transaction_id, 'user_id' => $cashier->id]);
    }

    public function test_legacy_check_in_without_operation_key_still_deducts_once(): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        $kit = $this->kit(1);
        $data = $this->checkInData($room);
        unset($data['operation_key']);

        $this->actingAs($cashier)->post(route('checkin.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('checkin.store'), $data)->assertSessionHasErrors('room_id');

        $this->assertSame(0, $kit->fresh()->stock_quantity);
        $this->assertFalse($kit->fresh()->is_available);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->assertDatabaseCount('folios', 1);
    }

    public function test_rejected_check_in_does_not_consume_a_kit(): void
    {
        $cashier = $this->cashier();
        $room = $this->room('occupied');
        $kit = $this->kit();

        $this->actingAs($cashier)->post(route('checkin.store'), $this->checkInData($room))->assertSessionHasErrors('room_id');

        $this->assertSame(10, $kit->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    public function test_transfer_conversion_and_extension_do_not_issue_another_kit(): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        $kit = $this->kit();
        $data = $this->checkInData($room);
        $data['rate_tier'] = '12h';
        $this->actingAs($cashier)->post(route('checkin.store'), $data)->assertSessionHasNoErrors();
        $folio = Folio::firstOrFail();
        $target = $this->room();

        $this->post(route('folios.convert', $folio), ['operation_key' => (string) Str::uuid(),
            'rate_tier' => '24h', 'reason' => 'Guest requested a longer stay'])->assertSessionHasNoErrors();
        $this->post(route('folios.extend', $folio), ['operation_key' => (string) Str::uuid(), 'hours' => 1])->assertSessionHasNoErrors();
        $this->post(route('folios.transfer', $folio), ['target_room_id' => $target->id])->assertSessionHasNoErrors();

        $this->assertSame(9, $kit->fresh()->stock_quantity);
        $this->assertSame(1, InventoryEvent::where('event_type', 'guest_amenity')->count());
        $this->assertSame('0.00', $folio->fresh()->pos_total);
    }

    public function test_seeded_hygiene_kit_is_free_and_tracks_inventory(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kit = PosItem::where('name', 'Guest Amenity Hygiene Kit')->firstOrFail();
        $this->assertSame('0.00', $kit->price);
        $this->assertTrue($kit->is_tracked);
    }

    public function test_zero_stock_allows_check_in_and_warns_without_negative_stock_or_duplicate_shortages(): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        $kit = $this->kit(0);
        $data = $this->checkInData($room);
        $warning = 'The free Guest Amenity Hygiene Kit is included, but its recorded stock is zero. Update its stock quantity in Inventory.';

        $this->actingAs($cashier)->post(route('checkin.store'), $data)->assertSessionHasNoErrors()
            ->assertSessionHas('success')->assertSessionHas('info', $warning);
        $this->post(route('checkin.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('info', $warning);
        $this->get(route('dashboard'))->assertSee($warning);

        $folio = Folio::firstOrFail();
        $this->assertSame('active', $folio->status);
        $this->assertSame('395.00', $folio->net_total);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame(0, $kit->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->assertDatabaseHas('inventory_events', ['pos_item_id' => $kit->id,
            'event_type' => 'amenity_shortage', 'quantity_change' => 0, 'balance_after' => 0,
            'reference_id' => $folio->transaction_id, 'user_id' => $cashier->id]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_missing_or_retired_kit_catalog_does_not_block_check_in(bool $retired): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        if ($retired) {
            $this->kit()->update(['is_retired' => true]);
        }

        $this->actingAs($cashier)->post(route('checkin.store'), $this->checkInData($room))
            ->assertSessionHasNoErrors()->assertSessionHas('success')
            ->assertSessionHas('info', 'The free Guest Amenity Hygiene Kit is included. Its inventory item is missing or retired; update the catalog and stock in Inventory.');

        $this->assertDatabaseCount('folios', 1);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_check_in_tracks_a_kit_that_was_previously_configured_as_an_untracked_service(): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        $kit = $this->kit();
        $kit->update(['price' => 50, 'is_tracked' => false, 'is_available' => false]);

        $this->actingAs($cashier)->post(route('checkin.store'), $this->checkInData($room))->assertSessionHasNoErrors();

        $this->assertSame(9, $kit->fresh()->stock_quantity);
        $this->assertSame('0.00', $kit->fresh()->price);
        $this->assertTrue($kit->fresh()->is_tracked);
        $this->assertSame('395.00', Folio::firstOrFail()->net_total);
    }

    public function test_check_in_failure_rolls_back_the_stock_movement_and_stay(): void
    {
        $cashier = $this->cashier();
        $room = $this->room();
        $kit = $this->kit();
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit storage unavailable'));

        $this->actingAs($cashier)->post(route('checkin.store'), $this->checkInData($room))->assertInternalServerError();

        $this->assertSame(10, $kit->fresh()->stock_quantity);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('guests', 0);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    public function test_admin_stay_cancellation_is_not_blocked_by_a_complimentary_kit_and_does_not_restock_a_used_kit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $admin->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $room = $this->room();
        $kit = $this->kit();
        $this->actingAs($admin)->post(route('checkin.store'), $this->checkInData($room))->assertSessionHasNoErrors();
        $folio = Folio::firstOrFail();

        $this->post(route('folios.cancel', $folio), ['operation_key' => (string) Str::uuid(),
            'reason' => 'Correcting a mistaken room check-in'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('voided', $folio->fresh()->status);
        $this->assertSame(9, $kit->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
    }

    public function test_upgrade_makes_only_the_kit_free_and_tracked_without_resetting_stock_or_movements(): void
    {
        $kit = $this->kit(7);
        $kit->update(['price' => 50, 'is_tracked' => false, 'is_available' => false]);
        $other = PosItem::create(['name' => 'Water', 'category' => 'Drinks', 'price' => 25,
            'stock_quantity' => 15, 'is_tracked' => false]);
        $event = InventoryEvent::create(['pos_item_id' => $kit->id, 'item_name' => $kit->name,
            'event_type' => 'recount', 'quantity_change' => 7, 'balance_after' => 7]);
        $before = $event->fresh()->attributesToArray();
        $migration = require database_path('migrations/2026_10_15_073512_make_guest_hygiene_kit_complimentary.php');

        $migration->up();

        $this->assertSame('0.00', $kit->fresh()->price);
        $this->assertTrue($kit->fresh()->is_tracked);
        $this->assertSame(7, $kit->fresh()->stock_quantity);
        $this->assertFalse($kit->fresh()->is_available);
        $this->assertSame('25.00', $other->fresh()->price);
        $this->assertSame(15, $other->fresh()->stock_quantity);
        $this->assertFalse($other->fresh()->is_tracked);
        $this->assertSame($before, $event->fresh()->attributesToArray());
    }

    private function cashier(): User
    {
        $cashier = User::factory()->create();
        $cashier->assignRole(Role::findOrCreate('cashier', 'web'));
        $cashier->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $cashier->givePermissionTo(Permission::findOrCreate('view_dashboard', 'web'));

        return $cashier;
    }

    private function room(string $status = 'available'): Room
    {
        return Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1,
            'name' => 'Classic', 'type' => 'Classic Room', 'status' => $status,
            'base_rate_3h' => 395, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100]);
    }

    private function kit(int $stock = 10): PosItem
    {
        return PosItem::create(['name' => 'Guest Amenity Hygiene Kit', 'category' => 'Services',
            'price' => 0, 'stock_quantity' => $stock, 'is_tracked' => true, 'is_available' => $stock > 0]);
    }

    /** @return array{operation_key: string, room_id: int, guest_name: string, headcount: int, rate_tier: string} */
    private function checkInData(Room $room): array
    {
        return ['operation_key' => (string) Str::uuid(), 'room_id' => $room->id,
            'guest_name' => 'Amenity guest', 'headcount' => 2, 'rate_tier' => '3h'];
    }
}
