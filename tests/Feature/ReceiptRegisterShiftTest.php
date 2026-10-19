<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReceiptRegisterShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Manila'));
    }

    #[TestWith(['day', [1, 2]])]
    #[TestWith(['night', [3, 4]])]
    #[TestWith(['all', [1, 2, 3, 4]])]
    public function test_receipt_shift_boundaries_do_not_double_count_and_keep_checkout_order(string $shift, array $included): void
    {
        $actor = $this->actor('owner');
        $records = [];
        foreach (['2026-10-17 06:29:59', '2026-10-17 06:30:00', '2026-10-17 18:29:59', '2026-10-17 18:30:00', '2026-10-18 06:29:59', '2026-10-18 06:30:00'] as $time) {
            $records[] = $this->stay($actor, '2026-10-16 12:00:00', $time);
        }

        $response = $this->actingAs($actor)->get(route('receipts.index', ['date' => '2026-10-17', 'shift' => $shift]));

        $expected = array_map(fn (int $index): int => $records[$index]->id, $included);
        $response->assertOk()->assertViewHas('receiptCount', count($included))->assertViewHas('guestCount', count($included) * 2)
            ->assertViewHas('folios', fn ($folios): bool => $folios->modelKeys() === $expected)
            ->assertViewHas('firstReceipt', $records[$included[0]]->receipt_number)
            ->assertViewHas('lastReceipt', $records[$included[array_key_last($included)]]->receipt_number);
    }

    #[TestWith(['2026-10-18 06:29:59', '2026-10-17'])]
    #[TestWith(['2026-10-18 06:30:00', '2026-10-18'])]
    public function test_default_operating_date_changes_at_six_thirty_manila_time(string $at, string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($at, 'Asia/Manila'));
        $this->actingAs($this->actor('owner'))->get(route('receipts.index'))->assertOk()->assertViewHas('date', $date);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('shiftActivity', fn ($activity): bool => $activity['date'] === $date);
    }

    public function test_night_shift_spans_two_receipt_dates_when_server_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $actor = $this->actor('owner');
        $first = $this->stay($actor, '2026-10-17 12:00:00', '2026-10-17 23:59:59');
        $last = $this->stay($actor, '2026-10-17 12:00:00', '2026-10-18 00:00:00');
        $before = $last->fresh()->getAttributes();

        $this->actingAs($actor)->get(route('receipts.index', ['date' => '2026-10-17', 'shift' => 'night']))->assertOk()
            ->assertViewHas('receiptCount', 2)->assertViewHas('folios', fn ($folios): bool => $folios->modelKeys() === [$first->id, $last->id]);

        $this->assertSame($before, $last->fresh()->getAttributes());
    }

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_admin_and_owner_see_actual_shift_check_ins_and_checkouts_independent_of_room_filters(string $role): void
    {
        $actor = $this->actor($role);
        $day = $this->stay($actor, '2026-10-17 06:30:00', '2026-10-17 18:30:00');
        $this->stay($actor, '2026-10-17 18:30:00', '2026-10-18 06:29:59');
        $this->stay($actor, '2026-10-17 06:29:59', '2026-10-17 06:30:00');
        $active = $this->stay($actor, '2026-10-17 10:00:00', '2026-10-17 13:00:00');
        $active->update(['status' => 'active', 'checked_out_at' => null, 'receipt_number' => null, 'receipt_issued_at' => null]);
        $active->guest->update(['headcount' => 3]);
        $cancelled = $this->stay($actor, '2026-10-17 12:00:00', '2026-10-17 13:00:00');
        $cancelled->update(['status' => 'voided']);
        $staff = $this->stay($actor, '2026-10-17 12:00:00', '2026-10-17 13:00:00');
        $staff->room->update(['is_staff_quarters' => true]);

        $response = $this->actingAs($actor)->get(route('dashboard', ['shift_date' => '2026-10-17', 'bstatus' => 'available']));

        $response->assertOk()->assertSee('Check-ins &amp; Checkouts', false);
        $activity = $response->viewData('shiftActivity');
        $this->assertSame(['stays' => 2, 'guests' => 5], $activity['shifts']['day']['check_ins']);
        $this->assertSame(['stays' => 1, 'guests' => 2], $activity['shifts']['day']['check_outs']);
        $this->assertSame(['stays' => 1, 'guests' => 2], $activity['shifts']['night']['check_ins']);
        $this->assertSame(['stays' => 2, 'guests' => 4], $activity['shifts']['night']['check_outs']);

        $day->update(['checked_in_at' => '2026-10-17 06:29:00']);
        $this->get(route('dashboard', ['shift_date' => '2026-10-17']))->assertViewHas('shiftActivity', fn ($data): bool => $data['shifts']['day']['check_ins'] === ['stays' => 1, 'guests' => 3]);
    }

    #[TestWith(['cashier'])]
    #[TestWith(['manager'])]
    #[TestWith(['front_desk'])]
    public function test_other_roles_do_not_receive_shift_activity_data_or_widget(string $role): void
    {
        $this->actingAs($this->actor($role))->get(route('dashboard'))->assertOk()->assertViewHas('shiftActivity', null)
            ->assertDontSee('Check-ins and checkouts by shift');
    }

    public function test_pagination_preserves_shift_and_totals_and_invalid_filters_are_rejected(): void
    {
        $actor = $this->actor('owner');
        for ($number = 0; $number < 31; $number++) {
            $this->stay($actor, '2026-10-17 17:00:00', '2026-10-18 02:00:00');
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-18 02:00:00', 'Asia/Manila'));
        $response = $this->actingAs($actor)->get(route('receipts.index', ['shift' => 'night', 'page' => 2]));
        $response->assertOk()->assertViewHas('receiptCount', 31)->assertViewHas('guestCount', 62);
        $this->assertCount(1, $response->viewData('folios')->items());
        $this->assertStringContainsString('shift=night', $response->viewData('folios')->url(1));
        $this->assertStringContainsString('date=2026-10-17', $response->viewData('folios')->url(1));
        $this->get(route('receipts.index', ['shift' => 'invalid']))->assertSessionHasErrors('shift');
        $this->get(route('dashboard', ['shift_date' => 'invalid']))->assertSessionHasErrors('shift_date');
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('view_dashboard', 'web'));

        return $actor;
    }

    private function stay(User $actor, string $arrival, string $checkout): Folio
    {
        $room = Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'available']);
        $arrival = CarbonImmutable::parse($arrival, 'Asia/Manila')->setTimezone(config('app.timezone'));
        $checkout = CarbonImmutable::parse($checkout, 'Asia/Manila')->setTimezone(config('app.timezone'));

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Shift guest', 'headcount' => 2])->id,
            'user_id' => $actor->id, 'transaction_id' => (string) Str::uuid(), 'rate_tier' => '3h', 'room_charge' => 395,
            'status' => 'checked_out', 'checked_in_at' => $arrival, 'expected_checkout_at' => $checkout, 'checked_out_at' => $checkout,
            'receipt_number' => 'SCTI-'.$checkout->setTimezone('Asia/Manila')->format('Ymd').'-'.str_pad((string) $room->id, 3, '0', STR_PAD_LEFT), 'receipt_issued_at' => $checkout]);
    }
}
