<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckoutCheckInTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-17 16:10:00');
    }

    public function test_cashier_explicitly_saves_late_arrival_and_recalculates_checkout_and_billing_once(): void
    {
        $cashier = $this->actor('cashier');
        $folio = $this->stay($cashier);
        $this->actingAs($cashier)->get(route('checkout.process', $folio))->assertOk()
            ->assertSee('Save check-in time')->assertSee('name="actual_checked_in_at"', false);
        $this->assertSame('2026-10-17 13:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
        $payload = $this->correction($folio);

        $this->post(route('checkout.check-in-time', $folio), $payload)->assertRedirect(route('checkout.process', $folio))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post(route('checkout.check-in-time', $folio), $payload)->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertSame('2026-10-17 11:00:00', $folio->checked_in_at->toDateTimeString());
        $this->assertSame('2026-10-17 14:00:00', $folio->expected_checkout_at->toDateTimeString());
        $bill = app(BillingService::class)->calculate($folio, now());
        $this->assertSame(2, $bill['excessHours']);
        $this->assertSame(655, $bill['grossTotal']);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertNull($folio->receipt_number);
        $audit = DB::table('audit_logs')->where('event', 'folio.checkin_time_corrected')->sole();
        $this->assertSame($cashier->id, $audit->user_id);
        $values = json_decode($audit->new_values, true);
        $this->assertSame($payload['reason'], $values['reason']);
        $this->assertSame('2026-10-17T13:00:00+08:00', $values['before']['checked_in_at']);
        $this->get(route('checkout.process', $folio))->assertOk()->assertViewHas('checkInTime', fn ($time): bool => $time->format('H:i') === '11:00');
    }

    #[TestWith(['cashier', false])]
    #[TestWith(['front_desk', false])]
    #[TestWith(['admin', true])]
    #[TestWith(['owner', true])]
    public function test_print_lock_is_enforced_except_for_admin_and_owner(string $role, bool $allowed): void
    {
        $actor = $this->actor($role);
        $folio = $this->stay($actor);
        $folio->forceFill(['billing_printed_at' => now()])->save();
        $page = $this->actingAs($actor)->get(route('checkout.process', $folio))->assertOk();
        $response = $this->post(route('checkout.check-in-time', $folio), $this->correction($folio));
        if ($allowed) {
            $page->assertSee('Save check-in time');
            $response->assertSessionHasNoErrors();
            $this->assertSame('2026-10-17 11:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
        } else {
            $page->assertDontSee('name="actual_checked_in_at"', false);
            $response->assertSessionHasErrors('stay_time');
            $this->assertSame('2026-10-17 13:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
        }
    }

    #[TestWith(['kitchen'])]
    #[TestWith(['manager'])]
    public function test_other_roles_cannot_save_checkin_time_even_with_manage_folios(string $role): void
    {
        $actor = $this->actor($role);
        $folio = $this->stay($actor);
        $this->actingAs($actor)->post(route('checkout.check-in-time', $folio), $this->correction($folio))->assertForbidden();
        $this->assertSame('2026-10-17 13:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
    }

    public function test_cashier_requires_manage_folios_permission_and_login(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $this->post(route('checkout.check-in-time', $folio), $this->correction($folio))->assertRedirect(route('login'));
        $actor->revokePermissionTo('manage_folios');
        $this->actingAs($actor)->post(route('checkout.check-in-time', $folio), $this->correction($folio))->assertForbidden();
    }

    public function test_future_times_missing_reasons_and_stale_forms_do_not_change_time(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $payload = $this->correction($folio);
        $url = route('checkout.check-in-time', $folio);
        $this->actingAs($actor)->post($url, array_replace($payload, ['actual_checked_in_at' => '2026-10-17T17:00']))->assertSessionHasErrors('checked_in_at');
        $this->post($url, array_replace($payload, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, array_replace($payload, ['expected_checked_in_at' => '2026-10-17 12:00:00']))->assertConflict();
        $this->assertSame('2026-10-17 13:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'folio.checkin_time_corrected')->count());
    }

    public function test_checkout_correction_keeps_stay_overlap_reservation_and_midnight_promo_checks(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $previous = $this->stay($actor);
        $previous->update(['room_id' => $folio->room_id, 'status' => 'checked_out', 'checked_in_at' => '2026-10-17 08:00:00', 'checked_out_at' => '2026-10-17 12:00:00']);
        $this->actingAs($actor)->post(route('checkout.check-in-time', $folio), $this->correction($folio))->assertSessionHasErrors('checked_in_at');
        $previous->update(['checked_out_at' => '2026-10-17 10:00:00']);
        Booking::create(['room_id' => $folio->room_id, 'user_id' => $actor->id, 'guest_name' => 'Reserved guest', 'headcount' => 2,
            'rate_tier' => '3h', 'arrival_at' => '2026-10-17 17:00:00', 'departure_at' => '2026-10-17 20:00:00', 'status' => 'scheduled']);
        $later = array_replace($this->correction($folio), ['actual_checked_in_at' => '2026-10-17T15:00']);
        $this->post(route('checkout.check-in-time', $folio), $later)->assertSessionHasErrors('room_id');
        $folio->update(['rate_tier' => 'promo']);
        $this->post(route('checkout.check-in-time', $folio), $this->correction($folio))->assertSessionHasErrors('checked_in_at');
        $this->assertSame('2026-10-17 13:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
    }

    #[TestWith(['checked_out', false])]
    #[TestWith(['voided', false])]
    #[TestWith(['active', true])]
    public function test_settled_cancelled_and_staff_stays_cannot_be_corrected(string $status, bool $staff): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $folio->update(['status' => $status]);
        $folio->room->update(['is_staff_quarters' => $staff]);
        $this->actingAs($actor)->post(route('checkout.check-in-time', $folio), $this->correction($folio))->assertConflict();
        $this->assertSame('2026-10-17 13:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
    }

    public function test_hotel_wall_time_is_saved_correctly_when_server_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $folio->update(['checked_in_at' => '2026-10-17 05:00:00']);
        $payload = $this->correction($folio);
        $this->actingAs($actor)->post(route('checkout.check-in-time', $folio), $payload)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-17 03:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function test_transferred_stay_time_correction_checks_the_original_room_history(bool $originalRoomConflict, bool $multipleTransfers): void
    {
        $cashier = $this->actor('cashier');
        $stay = $this->stay($cashier);
        $originalRoom = $stay->room;
        $stay->update(['checked_in_at' => '2026-10-17 08:00:00', 'expected_checkout_at' => '2026-10-17 11:00:00']);
        $previous = $this->stay($cashier);
        $previous->update(['status' => 'checked_out', 'checked_in_at' => '2026-10-17 10:00:00', 'checked_out_at' => '2026-10-17 11:00:00']);
        $previous->room->update(['status' => 'available']);
        $this->travelTo('2026-10-17 12:00:00');
        $this->actingAs($cashier)->post(route('folios.transfer', $stay), ['target_room_id' => $previous->room_id])->assertSessionHasNoErrors();
        if ($multipleTransfers) {
            $thirdRoomStay = $this->stay($cashier);
            $thirdRoomStay->update(['status' => 'voided']);
            $thirdRoomStay->room->update(['status' => 'available']);
            $this->post(route('folios.transfer', $stay), ['target_room_id' => $thirdRoomStay->room_id])->assertSessionHasNoErrors();
        }
        if ($originalRoomConflict) {
            $earlier = $this->stay($cashier);
            $earlier->update(['room_id' => $originalRoom->id, 'status' => 'checked_out', 'checked_in_at' => '2026-10-17 06:00:00', 'checked_out_at' => '2026-10-17 08:00:00']);
        }
        $data = array_replace($this->correction($stay->fresh()), ['actual_checked_in_at' => '2026-10-17T07:55']);
        $response = $this->post(route('checkout.check-in-time', $stay), $data);
        if ($originalRoomConflict) {
            $response->assertSessionHasErrors('checked_in_at');
            $this->assertSame('2026-10-17 08:00:00', $stay->fresh()->checked_in_at->toDateTimeString());
        } else {
            $response->assertSessionHasNoErrors();
            $this->assertSame('2026-10-17 07:55:00', $stay->fresh()->checked_in_at->toDateTimeString());
            $this->assertSame('2026-10-17 10:55:00', $stay->fresh()->expected_checkout_at->toDateTimeString());
        }
    }

    public function test_corrected_arrival_cannot_be_after_the_first_transfer(): void
    {
        $cashier = $this->actor('cashier');
        $stay = $this->stay($cashier);
        $destination = $this->stay($cashier);
        $destination->update(['status' => 'voided']);
        $destination->room->update(['status' => 'available']);
        $this->actingAs($cashier)->post(route('folios.transfer', $stay), ['target_room_id' => $destination->room_id])->assertSessionHasNoErrors();
        $this->travel(30)->minutes();
        $data = array_replace($this->correction($stay->fresh()), ['actual_checked_in_at' => '2026-10-17T16:20']);
        $this->post(route('checkout.check-in-time', $stay), $data)->assertSessionHasErrors('checked_in_at');
        $this->assertSame('2026-10-17 13:00:00', $stay->fresh()->checked_in_at->toDateTimeString());
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'record_payments', 'manage_shifts'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    }

    private function stay(User $actor): Folio
    {
        $room = Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'occupied', 'base_rate_3h' => 395, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100]);

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Late arrival', 'headcount' => 2])->id, 'user_id' => $actor->id,
            'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'status' => 'active', 'room_charge' => 395,
            'checked_in_at' => '2026-10-17 13:00:00', 'expected_checkout_at' => '2026-10-17 16:00:00']);
    }

    /** @return array<string, string> */
    private function correction(Folio $folio): array
    {
        return ['operation_key' => (string) Str::uuid(), 'actual_checked_in_at' => '2026-10-17T11:00',
            'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(), 'reason' => 'Cashier recorded the guest after arrival'];
    }
}
