<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\StatementLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PnlShiftPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-17 12:00:00');
    }

    #[TestWith(['day', [1, 2]])]
    #[TestWith(['night', [3, 4]])]
    #[TestWith(['all', [1, 2, 3, 4]])]
    public function test_shift_boundaries_include_every_financial_stream_once(string $shift, array $included): void
    {
        $actor = $this->actor('admin');
        $folios = [];
        foreach (['2026-10-17 06:29:59', '2026-10-17 06:30:00', '2026-10-17 18:29:59', '2026-10-17 18:30:00', '2026-10-18 06:29:59', '2026-10-18 06:30:00'] as $time) {
            $folios[] = $this->settlement($actor, $time);
            $order = Order::create(['user_id' => $actor->id, 'transaction_id' => (string) Str::uuid(), 'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 10, 'payment_method' => 'cash', 'cash_tendered' => 10]);
            $order->forceFill(['created_at' => $this->hotelTime($time)])->save();
            PaymentEntry::create(['user_id' => $actor->id, 'order_id' => $order->id, 'kind' => 'pos_sale', 'posted_at' => $this->hotelTime($time), 'cash_amount' => 10]);
            PaymentEntry::create(['user_id' => $actor->id, 'order_id' => $order->id, 'kind' => 'pos_refund', 'posted_at' => $this->hotelTime($time), 'cash_amount' => -1]);
            $entry = PaymentEntry::create(['user_id' => $actor->id, 'kind' => 'deposit', 'posted_at' => $this->hotelTime($time), 'cash_amount' => 7]);
            $line = StatementLine::factory()->create(['user_id' => $actor->id, 'posted_at' => $this->hotelTime($time), 'gross_amount' => 7, 'fee_amount' => 3, 'net_amount' => 4]);
            DB::table('statement_matches')->insert(['statement_line_id' => $line->id, 'payment_entry_id' => $entry->id, 'user_id' => $actor->id, 'amount' => 7, 'created_at' => now()]);
            $this->expense($actor, $time, 'approved', 2);
        }
        $params = ['range' => 'today', 'report_date' => '2026-10-17', 'shift' => $shift];
        $count = count($included);
        $expectedIds = array_map(fn (int $index): int => $folios[$index]->id, $included);

        $this->actingAs($actor)->get(route('admin.accounting.pnl', $params))->assertOk()
            ->assertViewHas('settledFolios', fn ($stays): bool => $stays->modelKeys() === $expectedIds)
            ->assertViewHas('grossRevenue', 110.0 * $count)->assertViewHas('salesReturns', 1.0 * $count)
            ->assertViewHas('netRevenueCollected', 109.0 * $count)->assertViewHas('cashCollections', 116.0 * $count)
            ->assertViewHas('totalOpex', 5.0 * $count)->assertViewHas('netOperatingProfit', 104.0 * $count);
        $csv = $this->get(route('admin.accounting.pnl.csv', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString('"NET SETTLED REVENUE",'.number_format(109 * $count, 2, '.', ''), $csv);
        $this->assertStringContainsString('"NET OPERATING PROFIT (EBITDA)",'.number_format(104 * $count, 2, '.', ''), $csv);
        $this->assertStringContainsString('Asia/Manila', $csv);
        $this->assertStringContainsString('Expenses counted by recorded time', $csv);
    }

    #[TestWith(['2026-10-18 00:01:00', '2026-10-17'])]
    #[TestWith(['2026-10-18 06:29:59', '2026-10-17'])]
    #[TestWith(['2026-10-18 06:30:00', '2026-10-18'])]
    public function test_today_uses_the_operating_date_and_pins_it_in_the_export(string $now, string $date): void
    {
        $this->travelTo($now);
        $this->actingAs($this->actor('owner'))->get(route('admin.accounting.pnl', ['range' => 'today']))->assertOk()
            ->assertViewHas('reportDate', $date)->assertViewHas('shift', 'all')
            ->assertViewHas('startDate', fn ($start): bool => $start->format('Y-m-d H:i:s') === $date.' 06:30:00')
            ->assertSee(route('admin.accounting.pnl.csv', ['range' => 'today', 'shift' => 'all', 'report_date' => $date]));
    }

    public function test_overnight_shift_uses_hotel_time_when_the_server_stores_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $actor = $this->actor('admin');
        $inside = $this->settlement($actor, '2026-10-18 01:30:00');
        $this->settlement($actor, '2026-10-18 06:30:00');
        $params = ['range' => 'today', 'shift' => 'night', 'report_date' => '2026-10-17'];

        $this->actingAs($actor)->get(route('admin.accounting.pnl', $params))->assertOk()
            ->assertViewHas('startDate', fn ($start): bool => $start->format('Y-m-d H:i:s') === '2026-10-17 10:30:00')
            ->assertViewHas('endDate', fn ($end): bool => $end->format('Y-m-d H:i:s') === '2026-10-17 22:30:00')
            ->assertViewHas('settledFolios', fn ($stays): bool => $stays->modelKeys() === [$inside->id]);
        $csv = $this->get(route('admin.accounting.pnl.csv', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString('10/17/2026 06:30 PM', $csv);
        $this->assertStringContainsString('10/18/2026 06:30 AM', $csv);
    }

    public function test_shift_expenses_use_recorded_time_but_custom_reports_keep_expense_date(): void
    {
        $actor = $this->actor('admin');
        $this->expense($actor, '2026-10-18 01:00:00', 'pending_review', 10);
        $this->expense($actor, '2026-10-18 02:00:00', 'rejected_paid', 20);
        $this->expense($actor, '2026-10-18 03:00:00', 'voided', 99);
        $this->expense($actor, '2026-10-18 06:30:00', 'approved', 40);
        $this->actingAs($actor)->get(route('admin.accounting.pnl', ['range' => 'today', 'shift' => 'night', 'report_date' => '2026-10-17']))->assertOk()
            ->assertViewHas('totalOpex', 30.0)->assertViewHas('expenses', fn ($expenses): bool => $expenses->count() === 2);
        $this->get(route('admin.accounting.pnl', ['range' => 'custom', 'start_date' => '2026-10-17', 'end_date' => '2026-10-17']))->assertOk()
            ->assertViewHas('totalOpex', 70.0);
    }

    public function test_revenue_follows_checkout_time_instead_of_checkin_time(): void
    {
        $actor = $this->actor('admin');
        $folio = $this->settlement($actor, '2026-10-17 19:00:00');
        $folio->update(['checked_in_at' => '2026-10-16 10:00:00']);
        $active = $this->settlement($actor, '2026-10-17 19:00:00');
        $active->update(['status' => 'active']);
        $this->actingAs($actor)->get(route('admin.accounting.pnl', ['range' => 'today', 'shift' => 'day', 'report_date' => '2026-10-17']))->assertOk()
            ->assertViewHas('grossRevenue', 0.0);
        $this->get(route('admin.accounting.pnl', ['range' => 'today', 'shift' => 'night', 'report_date' => '2026-10-17']))->assertOk()
            ->assertViewHas('settledFolios', fn ($stays): bool => $stays->modelKeys() === [$folio->id]);
    }

    #[TestWith(['shift', 'invalid'])]
    #[TestWith(['report_date', '2026-02-30'])]
    public function test_invalid_shift_filters_are_rejected_in_page_and_export(string $field, string $value): void
    {
        $this->actingAs($this->actor('admin'));
        foreach (['admin.accounting.pnl', 'admin.accounting.pnl.csv'] as $route) {
            $this->get(route($route, ['range' => 'today', $field => $value]))->assertSessionHasErrors($field);
        }
    }

    public function test_cashier_cannot_access_shift_pnl_or_export(): void
    {
        $this->actingAs($this->actor('cashier'));
        foreach (['admin.accounting.pnl', 'admin.accounting.pnl.csv'] as $route) {
            $this->get(route($route, ['range' => 'today', 'shift' => 'night']))->assertForbidden();
        }
    }

    private function actor(string $role): User
    {
        return User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
    }

    private function hotelTime(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Manila')->setTimezone(config('app.timezone'));
    }

    private function settlement(User $actor, string $time): Folio
    {
        $room = Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'available', 'base_rate_3h' => 395]);

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Shift guest', 'headcount' => 2])->id, 'user_id' => $actor->id,
            'transaction_id' => (string) Str::uuid(), 'rate_tier' => '3h', 'status' => 'checked_out', 'room_charge' => 100, 'gross_total' => 100, 'net_total' => 100,
            'checked_in_at' => $this->hotelTime($time)->subHours(3), 'checked_out_at' => $this->hotelTime($time), 'expected_checkout_at' => $this->hotelTime($time), 'payment_method' => 'cash', 'cash_tendered' => 100]);
    }

    private function expense(User $actor, string $time, string $status, int $amount): void
    {
        $expense = Expense::create(['user_id' => $actor->id, 'voucher_number' => (string) Str::uuid(), 'expense_date' => '2026-10-17', 'category' => 'other', 'description' => 'Shift expense', 'amount' => $amount, 'payment_source' => 'petty_cash', 'status' => $status]);
        $expense->forceFill(['created_at' => $this->hotelTime($time)])->save();
    }
}
