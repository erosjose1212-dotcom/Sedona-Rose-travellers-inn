<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftCalendar;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReviewIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_write_off_is_not_shown_as_a_pending_dashboard_task(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $folio = $this->folio(['force_checkout' => true]);
        ApprovalRequest::factory()->create(['kind' => 'write_off', 'source_type' => Folio::class, 'source_id' => $folio->id, 'status' => 'cancelled']);

        $this->actingAs(User::where('email', 'owner@sedonapms.com')->firstOrFail())->get(route('dashboard'))
            ->assertOk()->assertViewHas('pendingForceCheckouts', fn ($records): bool => $records->isEmpty())
            ->assertDontSee('Pending Force Checkout Requests');
    }

    public function test_recorded_write_off_request_links_to_the_independent_review_queue(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $folio = $this->folio(['force_checkout' => false]);
        ApprovalRequest::factory()->create(['kind' => 'write_off', 'source_type' => Folio::class, 'source_id' => $folio->id, 'status' => 'pending']);

        $this->actingAs(User::where('email', 'owner@sedonapms.com')->firstOrFail())->get(route('dashboard'))
            ->assertOk()->assertViewHas('pendingForceCheckouts', fn ($records): bool => $records->modelKeys() === [$folio->id])
            ->assertSee('Review recorded request')->assertDontSee('Approve Force Checkout?');
    }

    public function test_manager_reports_do_not_expose_an_unrelated_drawer_count_or_handover_notes(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Shift::create(['opened_by' => $cashier->id, 'closed_by' => $cashier->id, 'opened_at' => now()->subHours(2), 'closed_at' => now(), 'shift_date' => today(),
            'shift_type' => 'day', 'opening_float' => 100, 'cash_total' => 12345.67, 'handoff_notes' => 'PRIVATE-OTHER-DRAWER']);

        $this->actingAs($manager)->get(route('reports.index'))->assertOk()->assertDontSee('PRIVATE-OTHER-DRAWER')->assertDontSee('12,345.67')
            ->assertViewHas('pastShifts', fn ($records): bool => $records->isEmpty());
        $this->actingAs(User::where('email', 'owner@sedonapms.com')->firstOrFail())->get(route('reports.index'))->assertSee('PRIVATE-OTHER-DRAWER');
    }

    public function test_cashier_net_sales_include_applied_deposit_revenue_and_deduct_recorded_refunds(): void
    {
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->folio(['status' => 'checked_out', 'checked_out_at' => now(), 'checked_out_by' => $cashier->id,
            'gross_total' => 100, 'discount_amount' => 0, 'net_total' => 50, 'security_deposit' => 50, 'deposit_status' => 'applied_to_bill']);
        $order = Order::create(['user_id' => $cashier->id, 'transaction_id' => 'OWN-NET-SALE', 'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 100, 'payment_method' => 'cash', 'cash_tendered' => 100]);
        PaymentEntry::create(['user_id' => $cashier->id, 'order_id' => $order->id, 'kind' => 'pos_refund', 'cash_amount' => -30, 'gcash_amount' => 0, 'posted_at' => now()]);

        $this->actingAs($cashier)->get(route('dashboard'))->assertOk()->assertViewHas('myShiftRevenue', 170.0)->assertSee('Net sales');
    }

    public function test_front_desk_cash_matches_the_cashier_drawer_for_the_night_shift(): void
    {
        $this->withoutVite();
        config(['sedona.continuous_shift_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-09 12:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
        ShiftCalendar::scheduleCutover('2026-11-10');
        $this->travelTo(Carbon::parse('2026-11-11 19:29', 'Asia/Manila'));
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $otherCashier = User::factory()->create();
        $shift = Shift::create(['opened_by' => $otherCashier->id, 'opened_at' => now()->subDays(27),
            'shift_date' => today()->subDays(27), 'shift_type' => 'night']);
        $settled = $this->folio(['status' => 'checked_out', 'checked_out_at' => now()->subMinute(),
            'checked_out_by' => $cashier->id, 'gross_total' => 5470, 'net_total' => 5470]);
        $open = $this->folio();
        PaymentEntry::create(['kind' => 'settlement', 'folio_id' => $settled->id, 'user_id' => $cashier->id,
            'shift_id' => $shift->id, 'cash_amount' => 5470, 'posted_at' => now()->subMinute()]);
        PaymentEntry::create(['kind' => 'deposit', 'folio_id' => $open->id, 'user_id' => $cashier->id,
            'shift_id' => $shift->id, 'cash_amount' => 2000, 'posted_at' => now()->subMinutes(30)]);
        PaymentEntry::create(['kind' => 'deposit', 'folio_id' => $open->id, 'user_id' => $otherCashier->id,
            'shift_id' => $shift->id, 'cash_amount' => 900, 'posted_at' => now()->subMinutes(15)]);
        PaymentEntry::create(['kind' => 'deposit', 'folio_id' => $open->id, 'user_id' => $cashier->id,
            'shift_id' => $shift->id, 'cash_amount' => 700, 'posted_at' => now()->setTime(16, 59)]);

        $this->actingAs($cashier)->get(route('shifts.index'))->assertOk()
            ->assertViewHas('cashRevenue', 7470.0)->assertSee('7,470.00');
        $this->get(route('dashboard'))->assertOk()->assertViewHas('myShiftRevenue', 5470.0)
            ->assertViewHas('myShiftCashCollections', 7470.0)->assertSee('My Net Cash Collections')->assertSee('7,470.00');
    }

    /** @param array<string, mixed> $values */
    private function folio(array $values = []): Folio
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();

        return Folio::create($values + ['room_id' => Room::where('number', '5')->firstOrFail()->id, 'guest_id' => Guest::create(['name' => 'Dashboard review guest'])->id,
            'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'status' => 'active', 'rate_tier' => '3h', 'room_charge' => 100,
            'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(2)]);
    }
}
