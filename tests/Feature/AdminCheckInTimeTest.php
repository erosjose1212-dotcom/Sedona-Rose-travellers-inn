<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCheckInTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-13 12:00:00');
    }

    public function test_admin_corrects_declaration_time_checkout_billing_and_audit_once(): void
    {
        $cashier = $this->actor('cashier');
        $admin = $this->actor('admin');
        $folio = $this->folio($cashier);
        $folio->update(['extra_hours' => 1]);
        $folio->recalculate();
        $payload = $this->correction($folio);

        $this->actingAs($admin)->post('/admin/check-in-times/'.$folio->id, $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post('/admin/check-in-times/'.$folio->id, $payload)->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertSame('2026-10-13 11:00:00', $folio->checked_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 15:00:00', $folio->expected_checkout_at->format('Y-m-d H:i:s'));
        $this->assertSame('525.00', $folio->net_total);
        $this->assertSame($cashier->id, $folio->user_id);
        $bill = app(BillingService::class)->calculate($folio, Carbon::parse('2026-10-13 17:10:00'));
        $this->assertSame(2, $bill['excessHours']);
        $this->assertSame(785, $bill['grossTotal']);
        $this->assertSame(0, app(BillingService::class)->calculate($folio, Carbon::parse('2026-10-13 15:15:00'))['excessHours']);
        $this->assertSame(1, app(BillingService::class)->calculate($folio, Carbon::parse('2026-10-13 15:15:01'))['excessHours']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'folio.checkin_time_corrected')->count());
        $audit = DB::table('audit_logs')->where('event', 'folio.checkin_time_corrected')->first();
        $values = json_decode($audit->new_values, true);
        $this->assertSame($admin->id, (int) $audit->user_id);
        $this->assertSame('2026-10-13T12:00:00+08:00', $values['before']['checked_in_at']);
        $this->assertSame('2026-10-13T11:00:00+08:00', $values['after']['checked_in_at']);
        $this->assertSame($payload['reason'], $values['reason']);
        $this->get('/admin/check-in-times')->assertOk()->assertSee('RA declaration time')->assertSee($payload['reason']);
        $this->actingAs($cashier)->get(route('dashboard'))->assertSee('data-checkout-time="2026-10-13T15:00:00+08:00"', false);
    }

    public function test_newly_registered_admin_can_correct_a_stay_they_recorded_without_extra_direct_permissions(): void
    {
        $owner = $this->actor('owner');
        Role::findOrCreate('admin', 'web');
        $this->actingAs($owner)->post(route('admin.users.store'), ['name' => 'RA (Cashier)', 'email' => 'new-admin@example.test',
            'password' => 'test-password', 'role' => 'admin'])->assertSessionHasNoErrors();
        $admin = User::where('email', 'new-admin@example.test')->sole();
        $folio = $this->folio($admin);
        $this->assertSame(0, $admin->permissions()->count());

        $response = $this->actingAs($admin)->post(route('admin.check-in-times.update', $folio), $this->correction($folio));

        $response->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('2026-10-13 11:00:00', $folio->fresh()->checked_in_at->toDateTimeString());
        $this->get($response->headers->get('Location'))->assertSee('10/13/2026 11:00:00 AM');
    }

    #[TestWith(['cashier'])]
    #[TestWith(['manager'])]
    #[TestWith(['front_desk'])]
    #[TestWith(['kitchen'])]
    public function test_non_admin_roles_cannot_access_or_post_time_corrections(string $role): void
    {
        $actor = $this->actor($role);
        $folio = $this->folio($actor);
        $this->actingAs($actor)->get('/admin/check-in-times')->assertForbidden();
        $this->post('/admin/check-in-times/'.$folio->id, $this->correction($folio))->assertForbidden();
        $this->assertSame('2026-10-13 12:00:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'folio.checkin_time_corrected')->count());
    }

    public function test_guests_cannot_access_the_admin_time_editor(): void
    {
        $this->get('/admin/check-in-times')->assertRedirect(route('login'));
    }

    public function test_initial_checkin_uses_the_server_clock_and_rejects_supplied_times(): void
    {
        $actor = $this->actor('cashier');
        $room = $this->room('available');
        $payload = ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'];
        $this->actingAs($actor)->post(route('checkin.store'), $payload + ['checked_in_at' => '2026-10-13T11:00'])->assertSessionHasErrors('checked_in_at');
        $this->post(route('checkin.store'), $payload + ['record_earlier_arrival' => 1, 'actual_checked_in_at' => '2026-10-13T11:00', 'late_entry_reason' => 'Forgot check-in'])->assertSessionHasErrors(['record_earlier_arrival', 'actual_checked_in_at']);
        $this->assertDatabaseCount('folios', 0);
        $this->post(route('checkin.store'), $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('folios', ['room_id' => $room->id, 'checked_in_at' => '2026-10-13 12:00:00', 'expected_checkout_at' => '2026-10-13 15:00:00']);
        $this->get(route('dashboard'))->assertDontSee('Record earlier arrival')->assertDontSee('name="checked_in_at"', false);
    }

    public function test_correction_requires_reason_rejects_future_time_and_stale_forms(): void
    {
        $admin = $this->actor('admin');
        $folio = $this->folio($admin);
        $payload = $this->correction($folio);
        $url = '/admin/check-in-times/'.$folio->id;
        $this->actingAs($admin)->post($url, array_replace($payload, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, array_replace($payload, ['checked_in_at' => '2026-10-13T13:00']))->assertSessionHasErrors('checked_in_at');
        $folio->update(['checked_in_at' => '2026-10-13 11:45:00']);
        $this->post($url, $payload)->assertConflict();
        $this->assertSame('2026-10-13 11:45:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
    }

    public function test_correction_rejects_settled_stays_staff_quarters_and_overlapping_stays(): void
    {
        $admin = $this->actor('admin');
        $folio = $this->folio($admin);
        $url = '/admin/check-in-times/'.$folio->id;
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $this->actingAs($admin)->post($url, $this->correction($folio))->assertConflict();
        $folio->update(['status' => 'active', 'checked_out_at' => null]);
        $folio->room->update(['is_staff_quarters' => true]);
        $this->post($url, $this->correction($folio))->assertConflict();
        $folio->room->update(['is_staff_quarters' => false]);
        $previous = $this->folio($admin);
        $previous->update(['room_id' => $folio->room_id, 'status' => 'checked_out', 'checked_in_at' => '2026-10-13 08:00:00', 'checked_out_at' => '2026-10-13 11:30:00']);
        $this->post($url, $this->correction($folio))->assertSessionHasErrors('checked_in_at');
        $this->assertSame('2026-10-13 12:00:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
    }

    public function test_time_overrides_are_blocked_in_new_and_previously_approved_requests(): void
    {
        $cashier = $this->actor('cashier');
        $admin = $this->actor('admin');
        $folio = $this->folio($cashier);
        $this->actingAs($cashier)->post(route('approvals.store'), [
            'operation_key' => (string) Str::uuid(), 'kind' => 'folio_override', 'source_id' => $folio->id,
            'payload' => ['checked_in_at' => '2026-10-13T11:00'], 'reason' => 'Late declaration',
        ])->assertSessionHasErrors('checked_in_at');
        $approval = ApprovalRequest::create(['kind' => 'folio_override', 'source_type' => Folio::class, 'source_id' => $folio->id, 'requested_by' => $cashier->id,
            'reviewed_by' => $admin->id, 'status' => 'approved', 'source_hash' => str_repeat('0', 64), 'payload' => ['checked_in_at' => '2026-10-13 11:00:00'], 'reason' => 'Historical request']);
        $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('checked_in_at');
        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame('2026-10-13 12:00:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
    }

    public function test_cashier_cannot_change_time_through_checkout_drafts_or_bill_preview(): void
    {
        $cashier = $this->actor('cashier');
        $folio = $this->folio($cashier);
        $this->actingAs($cashier)->postJson(route('folios.deposit', $folio), ['checked_in_at' => '2026-10-13T11:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('checked_in_at');
        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'checked_in_at' => '2026-10-13T11:00', 'cash_tendered' => 1000])
            ->assertSessionHasErrors('checked_in_at');
        $this->get(route('folios.billing', ['folio' => $folio, 'checked_in_at' => '2026-10-13T11:00']))
            ->assertViewHas('checkInTime', fn (Carbon $time): bool => $time->format('H:i:s') === '12:00:00');
        $this->assertSame('active', $folio->fresh()->status);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame('2026-10-13 12:00:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
    }

    #[TestWith(['2026-10-13 12:30:00', false])]
    #[TestWith(['2026-10-13 13:00:00', true])]
    public function test_time_correction_respects_a_future_reservation(string $arrival, bool $allowed): void
    {
        $admin = $this->actor('admin');
        $folio = $this->folio($admin);
        $folio->update(['checked_in_at' => '2026-10-13 09:00:00', 'expected_checkout_at' => '2026-10-13 12:00:00']);
        Booking::create(['room_id' => $folio->room_id, 'user_id' => $admin->id, 'guest_name' => 'Reserved guest',
            'headcount' => 1, 'rate_tier' => '3h', 'arrival_at' => $arrival,
            'departure_at' => Carbon::parse($arrival)->addHours(3), 'status' => 'scheduled']);
        $data = array_replace($this->correction($folio), ['checked_in_at' => '2026-10-13T10:00']);

        $response = $this->actingAs($admin)->post(route('admin.check-in-times.update', $folio), $data);

        if ($allowed) {
            $response->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame('2026-10-13 13:00:00', $folio->fresh()->expected_checkout_at->format('Y-m-d H:i:s'));
        } else {
            $response->assertSessionHasErrors('room_id');
            $this->assertSame('2026-10-13 09:00:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'folio.checkin_time_corrected')->count());
        }
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'view_dashboard', 'record_payments', 'manage_shifts', 'view_all_shifts'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    }

    private function room(string $status = 'occupied'): Room
    {
        return Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => $status,
            'base_rate_3h' => 395, 'base_rate_6h' => 785, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100, 'base_rate_promo' => 995]);
    }

    private function folio(User $actor): Folio
    {
        return Folio::create(['room_id' => $this->room()->id, 'guest_id' => Guest::create(['name' => 'Declared guest', 'headcount' => 2])->id,
            'user_id' => $actor->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(3), 'room_charge' => 395, 'status' => 'active', 'pos_items' => []]);
    }

    /** @return array<string, string> */
    private function correction(Folio $folio): array
    {
        return ['operation_key' => (string) Str::uuid(), 'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(),
            'checked_in_at' => '2026-10-13T11:00', 'reason' => 'RA declared the guest at 11 AM; cashier entered the stay later.'];
    }
}
