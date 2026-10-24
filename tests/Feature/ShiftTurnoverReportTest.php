<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShiftTurnoverReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['sedona.continuous_shift_enabled' => true, 'sedona.shift_handover_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 07:00:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
    }

    #[TestWith(['day', 'Asia/Manila', 240.0, 100.0, 225.0])]
    #[TestWith(['night', 'Asia/Manila', 200.0, 100.0, 200.0])]
    #[TestWith(['night', 'UTC', 200.0, 100.0, 200.0])]
    public function test_cashier_money_is_scoped_to_the_operator_and_half_open_working_shift(string $period, string $timezone, float $cash, float $gcash, float $netCash): void
    {
        config(['app.timezone' => $timezone]);
        $actor = $this->actor();
        $other = $this->actor('raquel@sedonapms.com');
        $drawer = $this->drawer($actor);
        foreach (['2026-10-25 06:29:59', '2026-10-25 06:30:00', '2026-10-25 18:29:59', '2026-10-25 18:30:00', '2026-10-26 06:29:59', '2026-10-26 06:30:00'] as $at) {
            $this->payment($drawer, $actor, $at, 100, 50);
            $this->payment($drawer, $other, $at, 12345.67, 6789.12);
        }
        $this->payment($drawer, $actor, '2026-10-25 12:00:00', -10, -20, 'pos_refund');
        $this->payment($drawer, $actor, '2026-10-25 12:00:00', 20, 0, 'deposit');
        $order = Order::create(['transaction_id' => (string) Str::uuid(), 'type' => 'walkin_pos', 'user_id' => $actor->id, 'shift_id' => $drawer->id, 'status' => 'delivered', 'total' => 50, 'payment_method' => 'split', 'cash_tendered' => 30, 'gcash_amount' => 20]);
        $order->forceFill(['created_at' => $this->at('2026-10-25 11:00:00')])->save();
        $expense = Expense::create(['user_id' => $actor->id, 'shift_id' => $drawer->id, 'voucher_number' => 'OWN-EXPENSE', 'expense_date' => '2026-10-25', 'amount' => 5, 'description' => 'Own supplies', 'category' => 'other', 'status' => 'approved', 'payment_source' => 'cash_drawer']);
        $expense->forceFill(['created_at' => $this->at('2026-10-25 11:00:00')])->save();
        CashMovement::factory()->create(['shift_id' => $drawer->id, 'user_id' => $actor->id, 'amount' => -10, 'posted_at' => $this->at('2026-10-25 11:00:00'), 'business_date' => '2026-10-25']);
        $params = ['date' => '2026-10-25', 'shift' => $period, 'operator_id' => $other->id, 'property_access' => 1];

        $this->actingAs($actor)->get(route('shifts.index', $params))->assertOk()
            ->assertViewHas('cashRevenue', $cash)->assertViewHas('gcashCollections', $gcash)->assertViewHas('expectedCash', $netCash)
            ->assertViewHas('propertyAccess', false)->assertDontSee('98,765.43')->assertDontSee('12,345.67')->assertDontSee('6,789.12');
        $this->get($this->slip($drawer, $params))->assertOk()
            ->assertViewHas('cashSales', $cash)->assertViewHas('gcashSales', $gcash)->assertViewHas('expectedCash', $netCash)
            ->assertDontSee('98,765.43')->assertDontSee('12,345.67')->assertDontSee('6,789.12')
            ->assertDontSee('ACTUAL PHYSICAL DRAWER COUNT')->assertSee('MY NET GCASH COLLECTIONS');
    }

    public function test_closed_shared_drawer_does_not_leak_saved_totals_or_other_operators_history(): void
    {
        $actor = $this->actor();
        $incoming = $this->actor('raquel@sedonapms.com');
        $drawer = $this->drawer($actor);
        $this->payment($drawer, $incoming, '2026-10-25 22:00:00', 100, 200);
        $drawer->update(['closed_by' => $actor->id, 'closed_at' => $this->at('2026-10-26 06:30:00'), 'cash_total' => 88888.11, 'gcash_total' => 77777.22, 'expected_cash' => 99999.33]);

        $this->actingAs($incoming)->get($this->slip($drawer))->assertOk()
            ->assertViewHas('cashSales', 100.0)->assertViewHas('gcashSales', 200.0)
            ->assertViewHas('reportPeriod', fn ($period): bool => $period['date'] === '2026-10-25' && $period['shift'] === 'night')
            ->assertDontSee('88,888.11')->assertDontSee('77,777.22')->assertDontSee('99,999.33');
        $history = $this->get(route('shifts.index'))->assertOk()->viewData('pastShifts')->first();
        $this->assertArrayNotHasKey('cash_total', $history->getAttributes());
        $this->assertArrayNotHasKey('gcash_total', $history->getAttributes());
        $this->assertSame('77777.22', $drawer->fresh()->gcash_total);
    }

    public function test_admin_keeps_full_drawer_financial_access(): void
    {
        $drawer = $this->drawer($this->actor());
        $this->payment($drawer, $this->actor(), '2026-10-25 11:00:00', 100, 200);
        $this->payment($drawer, $this->actor('raquel@sedonapms.com'), '2026-10-25 11:00:00', 300, 400);

        $this->actingAs($this->actor('admin1@sedonapms.com'))->get($this->slip($drawer))->assertOk()
            ->assertViewHas('propertyAccess', true)->assertViewHas('cashSales', 400.0)->assertViewHas('gcashSales', 600.0)
            ->assertSee('98,765.43');
    }

    public function test_occupancy_handover_has_shift_guest_counts_current_rooms_and_real_transfer_history(): void
    {
        $actor = $this->actor();
        $drawer = $this->drawer($actor);
        foreach ([['2026-10-24 20:00:00', '2026-10-25 06:29:59'], ['2026-10-25 06:30:00', '2026-10-25 10:00:00'], ['2026-10-25 18:29:59', '2026-10-25 18:30:00'], ['2026-10-25 18:30:00', '2026-10-26 06:29:59'], ['2026-10-26 06:30:00', '2026-10-26 08:00:00']] as [$arrival, $departure]) {
            $this->stay($actor, $arrival, $departure);
        }
        $active = $this->stay($actor, '2026-10-24 20:00:00', null);
        $target = Room::where('number', '2')->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Asia/Manila'));
        $this->actingAs($actor)->post(route('folios.transfer', $active), ['target_room_id' => $target->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 07:00:00', 'Asia/Manila'));

        $response = $this->get($this->slip($drawer, ['date' => '2026-10-25', 'shift' => 'day']))->assertOk()
            ->assertSee('ROOM OCCUPANCY REPORT')->assertSee('ROOM TRANSFERS')->assertSee('Room 1 → Room 2')->assertSee('EXPECTED OUT');
        $handover = $response->viewData('handover');
        $this->assertSame(['stays' => 2, 'guests' => 4], $handover['activity']['shifts']['day']['check_ins']);
        $this->assertSame(['stays' => 1, 'guests' => 2], $handover['activity']['shifts']['day']['check_outs']);
        $this->assertSame(['stays' => 1, 'guests' => 2], $handover['activity']['shifts']['night']['check_ins']);
        $this->assertSame(['stays' => 2, 'guests' => 4], $handover['activity']['shifts']['night']['check_outs']);
        $this->assertSame([$target->id], $handover['rooms']->where('status', 'occupied')->values()->modelKeys());
        $this->assertCount(1, $handover['transfers']);
        $this->assertSame($active->id, $target->fresh()->activeFolio->id);
    }

    #[TestWith(['date', '2026-02-30'])]
    #[TestWith(['shift', 'all'])]
    public function test_invalid_periods_are_rejected_on_the_page_and_print_view(string $field, string $value): void
    {
        $actor = $this->actor();
        $drawer = $this->drawer($actor);
        $this->actingAs($actor)->get(route('shifts.index', [$field => $value]))->assertSessionHasErrors($field);
        $this->get($this->slip($drawer, [$field => $value]))->assertSessionHasErrors($field);
    }

    public function test_other_cashiers_cannot_open_a_closed_drawer_they_did_not_participate_in(): void
    {
        $drawer = $this->drawer($this->actor());
        $drawer->update(['closed_at' => $this->at('2026-10-25 18:30:00')]);
        $this->actingAs($this->actor('raquel@sedonapms.com'))->get($this->slip($drawer))->assertForbidden();
    }

    public function test_remaining_guest_slips_keep_their_references_without_barcodes(): void
    {
        $actor = $this->actor();
        $folio = $this->stay($actor, '2026-10-25 08:00:00', '2026-10-25 11:00:00');
        $folio->update(['security_deposit' => 500]);
        foreach (['folios.gate_pass', 'folios.deposit_slip', 'folios.deposit_refund', 'folios.orderslip'] as $name) {
            $response = $this->actingAs($actor)->get(route($name, $folio))->assertOk()
                ->assertDontSee('shape-rendering="crispEdges"', false)->assertDontSee('barcode-box');
            $response->assertSee($name === 'folios.gate_pass' ? $response->viewData('ticketNo') : $folio->transaction_id);
        }
    }

    public function test_legacy_settlements_use_the_checkout_operator_and_journaled_sales_are_not_counted_twice(): void
    {
        $actor = $this->actor();
        $other = $this->actor('raquel@sedonapms.com');
        $drawer = $this->drawer($actor);
        $legacy = $this->stay($other, '2026-10-25 08:00:00', '2026-10-25 11:00:00');
        $legacy->update(['checked_out_by' => $actor->id, 'payment_method' => 'cash', 'cash_tendered' => 395]);
        $otherLegacy = $this->stay($actor, '2026-10-25 08:00:00', '2026-10-25 11:00:00');
        $otherLegacy->update(['checked_out_by' => $other->id, 'payment_method' => 'cash', 'cash_tendered' => 999]);
        $journaled = $this->stay($other, '2026-10-25 08:00:00', '2026-10-25 11:00:00');
        $journaled->update(['checked_out_by' => $actor->id, 'payment_method' => 'cash', 'cash_tendered' => 300]);
        PaymentEntry::create(['folio_id' => $journaled->id, 'shift_id' => $drawer->id, 'user_id' => $actor->id, 'kind' => 'settlement', 'cash_amount' => 300, 'gcash_amount' => 0, 'posted_at' => $this->at('2026-10-25 11:00:00')]);

        $this->actingAs($actor)->get($this->slip($drawer, ['date' => '2026-10-25', 'shift' => 'day']))->assertOk()
            ->assertViewHas('cashSales', 695.0)->assertViewHas('gcashSales', 0.0);
    }

    private function actor(string $email = 'pau@sedonapms.com'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function drawer(User $actor): Shift
    {
        return Shift::create(['opened_by' => $actor->id, 'opened_at' => $this->at('2026-10-24 06:30:00'), 'shift_date' => '2026-10-24', 'shift_type' => 'day', 'opening_float' => 98765.43]);
    }

    private function at(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Manila')->setTimezone(config('app.timezone'));
    }

    private function payment(Shift $shift, User $actor, string $at, float $cash, float $gcash, string $kind = 'settlement'): PaymentEntry
    {
        return PaymentEntry::create(['shift_id' => $shift->id, 'user_id' => $actor->id, 'kind' => $kind, 'cash_amount' => $cash, 'gcash_amount' => $gcash, 'posted_at' => $this->at($at), 'gcash_reference' => 'TEST-'.Str::uuid()]);
    }

    private function stay(User $actor, string $arrival, ?string $departure): Folio
    {
        $room = Room::where('number', '1')->firstOrFail();
        if ($departure === null) {
            $room->update(['status' => 'occupied']);
        }

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Turnover guest', 'headcount' => 2])->id, 'user_id' => $actor->id,
            'transaction_id' => 'TEST-'.Str::uuid(), 'rate_tier' => '24h', 'room_charge' => 2100, 'gross_total' => 2100, 'net_total' => 2100,
            'status' => $departure === null ? 'active' : 'checked_out', 'checked_in_at' => $this->at($arrival), 'checked_out_at' => $departure ? $this->at($departure) : null,
            'expected_checkout_at' => $this->at('2026-10-26 20:00:00')]);
    }

    private function slip(Shift $shift, array $query = []): string
    {
        return route('shifts.remittance_slip', $shift).($query ? '?'.http_build_query($query) : '');
    }
}
