<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Order;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BusinessDayService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContinuousCashierShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashiers_share_the_existing_drawer_and_each_payment_retains_its_operator(): void
    {
        $shift = $this->drawer();
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $this->actingAs($shift->openedBy)->post(route('logout'))->assertRedirect();
        $room = Room::where('number', '29')->firstOrFail();
        $this->actingAs($incoming)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->firstOrFail();
        $this->get(route('checkout.process', $folio))->assertSee('Continuous cashier shift active');
        $this->get(route('shifts.index'))->assertSee('One continuous shift is shared by all cashiers')->assertDontSee('Close Shift &amp; Record Count', false)->assertDontSee('Count and accept handover');

        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('shifts', 1);
        $this->assertNull($shift->fresh()->closed_at);
        $this->assertSame('1250.00', $shift->fresh()->opening_float);
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'shift_id' => $shift->id, 'user_id' => $incoming->id, 'cash_amount' => 395]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.settlement', 'user_id' => $incoming->id]);
    }

    public function test_continuous_drawer_cannot_be_closed_or_replaced_by_another_shift(): void
    {
        $shift = $this->drawer();
        $original = $shift->refresh()->getAttributes();

        $this->actingAs($shift->openedBy)->post(route('shifts.close', $shift), ['denominations' => ['coins' => 1250]])->assertStatus(409);
        $this->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 0])->assertSessionHasErrors('shift');

        $this->assertSame($original, $shift->refresh()->getAttributes());
        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_continuous_drawer_stays_open_across_business_days_without_blocking_day_closing(): void
    {
        $shift = $this->drawer();
        $this->travel(1)->days();

        $snapshot = app(BusinessDayService::class)->snapshot('2026-10-13');

        $this->assertSame([], $snapshot['exceptions']);
        $this->assertTrue($snapshot['continuous_shift_enabled']);
        $this->assertSame($shift->id, Shift::whereNull('closed_at')->sole()->id);
        $this->assertSame('1250.00', $shift->fresh()->opening_float);
    }

    public function test_shared_drawer_still_requires_cashier_payment_access(): void
    {
        $shift = $this->drawer();
        $actor = User::factory()->create();
        $actor->givePermissionTo('manage_shifts');

        $this->assertFalse(Gate::forUser($actor)->allows('operate-shift', $shift));

        $this->assertNull($shift->fresh()->closed_at);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_shared_drawer_dashboard_uses_the_current_working_shift_for_each_cashier(): void
    {
        $shift = $this->drawer();
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        foreach ([$shift->openedBy, $incoming] as $cashier) {
            Order::create(['user_id' => $cashier->id, 'shift_id' => $shift->id, 'transaction_id' => (string) Str::uuid(),
                'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 60, 'created_at' => now()]);
        }
        $this->travel(1)->days();

        foreach ([[$incoming, 95], [$shift->openedBy, 120]] as [$cashier, $amount]) {
            Order::create(['user_id' => $cashier->id, 'shift_id' => $shift->id, 'transaction_id' => (string) Str::uuid(),
                'type' => 'walkin_pos', 'status' => 'delivered', 'total' => $amount]);
        }

        foreach ([[$incoming, 95.0], [$shift->openedBy, 120.0]] as [$cashier, $amount]) {
            $this->actingAs($cashier)->get(route('dashboard'))->assertOk()
                ->assertViewHas('myShiftRevenue', $amount)->assertViewHas('myOrdersCount', 1)
                ->assertViewHas('myShiftLabel', 'Day shift · 10/13 06:30 AM–10/13 06:30 PM');
        }
    }

    private function drawer(): Shift
    {
        $this->withoutVite();
        $this->travelTo('2026-10-12 12:00:00');
        config(['sedona.continuous_shift_enabled' => true, 'sedona.shift_handover_enabled' => false]);
        $this->seed(DatabaseSeeder::class);

        return Shift::create(['opened_by' => User::where('email', 'pau@sedonapms.com')->firstOrFail()->id,
            'opened_at' => now(), 'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 1250]);
    }
}
