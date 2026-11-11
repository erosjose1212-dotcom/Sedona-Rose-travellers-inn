<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\ShiftCalendar;
use App\Services\ShiftReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CourtOperationsChangeTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['owner'])]
    #[TestWith(['admin'])]
    public function test_admin_and_owner_can_preview_and_correct_the_room_7_bill_once(string $role): void
    {
        $folio = $this->room7();
        $this->actingAs($this->operator($role));
        $originalPrint = ['operation_key' => (string) Str::uuid()];
        $this->post(route('folios.billing.print', $folio), $originalPrint)->assertOk()->assertDontSee('EXTENSION CORRECTED');
        $preview = $this->get(route('folios.extension.preview', [$folio, 'hours' => 3]))
            ->assertSee('1,265.00')->assertSee('875.00')->viewData('preview');
        $payload = ['hours' => 3, 'reason' => 'Guest requested three hours', 'preview_key' => $preview['key'], 'operation_key' => (string) Str::uuid()];

        $this->post(route('folios.extension.correct', $folio), $payload)->assertRedirect(route('checkout.process', $folio));
        $this->post(route('folios.extension.correct', $folio), $payload)->assertRedirect(route('checkout.process', $folio));

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'extra_hours' => 3, 'net_total' => 875, 'pos_total' => 90, 'room_charge' => 395,
            'expected_checkout_at' => '2026-11-09 14:00:00', 'user_id' => $folio->user_id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'folio.extension_corrected', 'user_id' => auth()->id(), 'auditable_id' => $folio->id]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'folio.extension_corrected')->count());
        $this->get(route('folios.billing', $folio))->assertOk()->assertSee('EXTENSION CORRECTED');
        $this->post(route('folios.billing.print', $folio), ['operation_key' => (string) Str::uuid()])
            ->assertOk()->assertViewHas('receiptCopy', 2)->assertSee('EXTENSION CORRECTED');
        $this->post(route('folios.billing.print', $folio), $originalPrint)
            ->assertOk()->assertViewHas('receiptCopy', 1)->assertDontSee('EXTENSION CORRECTED');
        $this->assertDatabaseCount('payment_entries', 0);
    }

    #[TestWith(['cashier'])]
    public function test_other_roles_cannot_use_the_extension_correction_endpoint(string $role): void
    {
        $folio = $this->room7();
        $this->actingAs($this->operator($role));

        $this->get(route('folios.extension.preview', [$folio, 'hours' => 3]))->assertForbidden();
        $this->post(route('folios.extension.correct', $folio), ['hours' => 3])->assertForbidden();

        $this->assertSame(6, (int) $folio->fresh()->extra_hours);
    }

    #[TestWith(['owner'])]
    #[TestWith(['admin'])]
    public function test_saved_extension_can_be_corrected_beyond_the_previous_cap(string $role): void
    {
        $folio = $this->room7();
        $this->actingAs($this->operator($role));
        $preview = $this->get(route('folios.extension.preview', [$folio, 'hours' => 8761]))
            ->assertOk()->viewData('preview');

        $this->post(route('folios.extension.correct', $folio), [
            'hours' => 8761, 'reason' => 'Corrected extension record',
            'preview_key' => $preview['key'], 'operation_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('checkout.process', $folio));

        $this->assertSame(8761, (int) $folio->fresh()->extra_hours);
    }

    #[TestWith(['active'])]
    #[TestWith(['checked_out'])]
    public function test_stale_preview_and_settled_bill_cannot_be_overwritten(string $status): void
    {
        $folio = $this->room7();
        $this->actingAs($this->operator('owner'));
        $preview = $this->get(route('folios.extension.preview', [$folio, 'hours' => 3]))->viewData('preview');
        $folio->update(['status' => $status, 'extra_hours' => 5]);

        $response = $this->post(route('folios.extension.correct', $folio), ['hours' => 3, 'reason' => 'Correct the extension',
            'preview_key' => $preview['key'], 'operation_key' => (string) Str::uuid()]);

        if ($status === 'active') {
            $response->assertSessionHasErrors('hours');
        } else {
            $response->assertConflict();
        }
        $this->assertSame(5, (int) $folio->fresh()->extra_hours);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'folio.extension_corrected']);
    }

    #[TestWith(['2026-11-10 04:59:59', '2026-11-09', 'night'])]
    #[TestWith(['2026-11-10 05:00:00', '2026-11-10', 'day'])]
    #[TestWith(['2026-11-10 16:59:59', '2026-11-10', 'day'])]
    #[TestWith(['2026-11-10 17:00:00', '2026-11-10', 'night'])]
    public function test_new_shift_and_finance_date_share_the_manila_boundaries(string $time, string $date, string $shift): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'Asia/Manila'));
        ShiftCalendar::scheduleCutover('2026-11-10');
        $at = CarbonImmutable::parse($time, 'Asia/Manila');

        $this->assertSame($date, app(BusinessDayService::class)->date($at));
        $this->assertSame($shift, ShiftCalendar::shift($at));
    }

    public function test_cutover_shortens_only_the_last_old_night_and_preserves_earlier_periods(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'Asia/Manila'));
        ShiftCalendar::scheduleCutover('2026-11-10');
        $shifts = app(ShiftReportService::class);
        [$start, $end] = app(BusinessDayService::class)->bounds('2026-11-09');

        $this->assertSame('6:30 AM–next 6:30 AM', ShiftCalendar::label('2026-11-08'));
        $this->assertSame('6:30 PM–next 5:00 AM', ShiftCalendar::label('2026-11-09', 'night'));
        $this->assertSame('5:00 AM–5:00 PM', ShiftCalendar::label('2026-11-10', 'day'));
        $this->assertSame(1350.0, $start->diffInMinutes($end));
        $this->assertTrue($end->equalTo($shifts->period('2026-11-10', 'day')['start']));
        $this->assertTrue($end->equalTo($shifts->period('2026-11-09', 'night')['end']));
    }

    public function test_blank_cutover_keeps_legacy_dates(): void
    {

        $this->assertSame('6:30 AM–next 6:30 AM', ShiftCalendar::label('2026-11-10'));
        $this->assertSame('2026-11-09', ShiftCalendar::date(CarbonImmutable::parse('2026-11-10 05:00:00', 'Asia/Manila')));
    }

    public function test_backdated_cutover_preserves_the_business_date_of_a_posted_payment(): void
    {
        $postedAt = CarbonImmutable::parse('2026-11-10 05:30', 'Asia/Manila');
        $this->travelTo($postedAt);
        $actor = User::factory()->create();
        $finance = app(BusinessDayService::class);
        $payment = PaymentEntry::create([
            'user_id' => $actor->id, 'kind' => 'pos_sale', 'cash_amount' => 130,
            'gcash_amount' => 0, 'posted_at' => $postedAt->setTimezone(config('app.timezone')),
        ]);

        $this->artisan('pms:schedule-shift-cutover', ['date' => '2026-11-10'])->assertFailed();

        $this->assertSame('2026-11-09', $finance->date($postedAt));
        $this->assertSame([$payment->id], array_column($finance->snapshot('2026-11-09')['payments'], 'id'));
        $this->assertSame([], $finance->snapshot('2026-11-10')['payments']);
    }

    public function test_future_cutover_rejects_a_posting_already_in_the_affected_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'Asia/Manila'));
        $postedAt = CarbonImmutable::parse('2026-11-10 05:30', 'Asia/Manila');
        PaymentEntry::create([
            'user_id' => User::factory()->create()->id, 'kind' => 'pos_sale', 'cash_amount' => 130,
            'gcash_amount' => 0, 'posted_at' => $postedAt->setTimezone(config('app.timezone')),
        ]);

        $this->artisan('pms:schedule-shift-cutover', ['date' => '2026-11-10'])
            ->expectsOutput('Existing postings or a day-close snapshot overlap the proposed cutover.')
            ->assertFailed();

        $this->assertSame('2026-11-09', ShiftCalendar::date($postedAt));
    }

    public function test_future_schedule_is_idempotent_and_cannot_be_moved(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'Asia/Manila'));
        $this->artisan('pms:schedule-shift-cutover', ['date' => '2026-11-10'])->assertSuccessful();
        $this->artisan('pms:schedule-shift-cutover', ['date' => '2026-11-10'])->assertSuccessful();
        $this->artisan('pms:schedule-shift-cutover', ['date' => '2026-11-11'])->assertFailed();

        $this->assertSame('5:00 AM–5:00 PM', ShiftCalendar::label('2026-11-10', 'day'));
    }

    private function operator(string $role): User
    {
        $user = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        $user->givePermissionTo(Permission::findOrCreate('manage_folios', 'web'));

        return $user;
    }

    private function room7(): Folio
    {
        $this->withoutVite();
        $this->travelTo('2026-11-09 08:00:00');
        $room = Room::create(['number' => '7', 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'occupied', 'base_rate_3h' => 395]);
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'QA guest'])->id,
            'user_id' => $this->operator('cashier')->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h',
            'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(9), 'room_charge' => 395, 'status' => 'active',
            'extra_hours' => 6, 'pos_items' => [['name' => 'Red Horse', 'qty' => 1, 'price' => 90, 'subtotal' => 90]]]);
        $folio->recalculate();

        return $folio;
    }
}
