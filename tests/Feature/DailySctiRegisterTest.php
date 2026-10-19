<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailySctiRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-14 12:00:00');
    }

    public function test_new_check_ins_share_a_daily_sequence_across_cashiers_and_ignore_the_legacy_counter(): void
    {
        DB::table('document_sequences')->where('name', 'scti')->update(['last_number' => 998997]);
        $first = $this->actor();
        $second = $this->actor();
        $legacy = $this->folio($first, 'SCTI-998997');
        foreach ([$first, $second] as $cashier) {
            $room = $this->room();
            $this->actingAs($cashier)->post(route('checkin.store'), ['operation_key' => (string) Str::uuid(),
                'room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(['SCTI-998997', 'STAY-20261014-001', 'STAY-20261014-002'], Folio::orderBy('id')->pluck('transaction_id')->all());
        $this->assertSame('SCTI-998997', $legacy->fresh()->transaction_id);
        $this->assertDatabaseHas('document_sequences', ['name' => 'scti', 'last_number' => 998997]);
        $this->assertDatabaseHas('document_sequences', ['name' => 'stay:20261014', 'last_number' => 2]);
    }

    public function test_daily_number_restarts_at_manila_midnight_even_when_server_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:59:59', 'UTC'));

        $this->assertSame('STAY-20261014-001', Folio::generateTransactionId());
        $this->travelTo(CarbonImmutable::parse('2026-10-14 16:00:00', 'UTC'));
        $this->assertSame('STAY-20261015-001', Folio::generateTransactionId());
        $this->assertSame('STAY-20261015-002', Folio::generateTransactionId());
        $this->assertDatabaseHas('document_sequences', ['name' => 'stay:20261014', 'last_number' => 1]);
    }

    public function test_daily_counter_does_not_wrap_after_999_or_reuse_an_existing_reference(): void
    {
        $actor = $this->actor();
        $this->folio($actor, 'STAY-20261014-001');
        $this->assertSame('STAY-20261014-002', Folio::generateTransactionId());
        DB::table('document_sequences')->where('name', 'stay:20261014')->update(['last_number' => 999]);

        $this->assertSame('STAY-20261014-1000', Folio::generateTransactionId());
    }

    public function test_retry_on_the_next_day_returns_the_original_stay_and_keeps_the_new_day_at_001(): void
    {
        $actor = $this->actor();
        $room = $this->room();
        $payload = ['operation_key' => (string) Str::uuid(), 'room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'];
        $this->actingAs($actor)->post(route('checkin.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo('2026-10-15 01:00:00');

        $this->post(route('checkin.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('folios', 1);
        $this->assertDatabaseHas('folios', ['transaction_id' => 'STAY-20261014-001']);
        $this->assertSame('STAY-20261015-001', Folio::generateTransactionId());
    }

    #[TestWith(['cashier'])]
    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    #[TestWith(['front_desk'])]
    public function test_register_counts_stays_and_people_once_and_keeps_cancelled_and_legacy_references_visible(string $role): void
    {
        $actor = $this->actor($role);
        $active = $this->folio($actor, headcount: 2);
        $this->folio($actor, status: 'checked_out', headcount: 3);
        $this->folio($actor, status: 'voided', headcount: 5);
        $legacy = $this->folio($actor, 'SCTI-998997', headcount: 1);
        $staff = $this->folio($actor, 'STAFF-12-TEST', headcount: 9);
        $staff->room->update(['is_staff_quarters' => true]);
        $tomorrow = $this->folio($actor, 'STAY-20261015-001', headcount: 8);
        PaymentEntry::create(['folio_id' => $active->id, 'user_id' => $actor->id, 'kind' => 'deposit', 'cash_amount' => 100, 'posted_at' => now()]);
        PaymentEntry::create(['folio_id' => $active->id, 'user_id' => $actor->id, 'kind' => 'deposit_refund', 'cash_amount' => 100, 'posted_at' => now()]);

        $response = $this->actingAs($actor)->get(route('checkins.index', ['date' => '2026-10-14']))->assertOk();

        $response->assertViewHas('counts', ['registered' => 4, 'stays' => 3, 'guests' => 6, 'cancelled' => 1])
            ->assertViewHas('lastNumber', 3)->assertSee('STAY-20261014-001')->assertSee('Cancelled')
            ->assertSee($legacy->transaction_id)->assertDontSee($staff->transaction_id)->assertDontSee($tomorrow->transaction_id);
        $this->assertSame([1, 2, 3, null], $response->viewData('folios')->getCollection()->map(fn (Folio $folio): ?int => $folio->dailySequenceNumber())->all());
    }

    public function test_time_correction_and_overnight_checkout_keep_the_stay_in_its_original_receipt_day(): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $folio->update(['checked_in_at' => CarbonImmutable::parse('2026-10-13 23:50:00'),
            'expected_checkout_at' => CarbonImmutable::parse('2026-10-14 02:50:00')]);
        $this->travelTo('2026-10-15 01:00:00');
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now()]);

        $this->actingAs($actor)->get(route('checkins.index', ['date' => '2026-10-14']))->assertOk()
            ->assertSee('STAY-20261014-001')->assertSee('10/13/2026 11:50 PM')->assertViewHas('counts', ['registered' => 1, 'stays' => 1, 'guests' => 1, 'cancelled' => 0]);
        $this->get(route('checkins.index', ['date' => '2026-10-15']))->assertOk()->assertViewHas('counts', ['registered' => 0, 'stays' => 0, 'guests' => 0, 'cancelled' => 0]);
        $this->assertSame('STAY-20261014-001', $folio->fresh()->transaction_id);
    }

    public function test_pagination_keeps_whole_day_totals_and_the_selected_date(): void
    {
        $actor = $this->actor();
        for ($index = 0; $index < 35; $index++) {
            $this->folio($actor);
        }
        $this->travelTo('2026-10-15 12:00:00');

        $response = $this->actingAs($actor)->get(route('checkins.index', ['date' => '2026-10-14', 'page' => 2]))->assertOk();

        $response->assertViewHas('counts', ['registered' => 35, 'stays' => 35, 'guests' => 35, 'cancelled' => 0])
            ->assertSee('STAY-20261014-031')->assertSee('STAY-20261014-035')->assertDontSee('STAY-20261014-001');
        $this->assertCount(5, $response->viewData('folios')->items());
        $this->assertStringContainsString('date=2026-10-14', $response->viewData('folios')->url(1));
    }

    public function test_invalid_dates_are_rejected_and_empty_days_have_zero_totals(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->from(route('checkins.index'))->get(route('checkins.index', ['date' => '2026-02-31']))->assertSessionHasErrors('date');
        $this->get(route('checkins.index'))->assertOk()->assertSee('No stays recorded for this date.')
            ->assertViewHas('counts', ['registered' => 0, 'stays' => 0, 'guests' => 0, 'cancelled' => 0]);
    }

    public function test_guests_and_kitchen_staff_cannot_access_the_register(): void
    {
        $this->get(route('checkins.index'))->assertRedirect(route('login'));
        $kitchen = User::factory()->create()->assignRole(Role::findOrCreate('kitchen', 'web'));

        $this->actingAs($kitchen)->get(route('checkins.index'))->assertForbidden();
    }

    private function actor(string $role = 'cashier'): User
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));

        return $actor;
    }

    private function room(): Room
    {
        return Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'available', 'base_rate_3h' => 395]);
    }

    private function folio(User $actor, ?string $reference = null, string $status = 'active', int $headcount = 1): Folio
    {
        return Folio::create(['room_id' => $this->room()->id, 'guest_id' => Guest::create(['name' => 'Register guest', 'headcount' => $headcount])->id,
            'user_id' => $actor->id, 'transaction_id' => $reference ?? Folio::generateTransactionId(), 'rate_tier' => '3h',
            'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'status' => $status, 'room_charge' => 395]);
    }
}
