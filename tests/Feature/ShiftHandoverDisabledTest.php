<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Folio;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BusinessDayService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShiftHandoverDisabledTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['pending'])]
    #[TestWith(['disputed'])]
    #[TestWith(['variance_approved'])]
    public function test_existing_handovers_do_not_block_a_new_shift_or_checkout_and_remain_unchanged(string $status): void
    {
        $cashier = $this->cashier();
        $previous = $this->closedShift($cashier, $status);
        $original = $previous->refresh()->getAttributes();
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '29')->firstOrFail();
        $this->actingAs($incoming)->post(route('checkin.store'), [
            'room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h',
        ])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->firstOrFail();
        $this->get(route('checkout.process', $folio))->assertSee('Start cashier shift')->assertDontSee('Waiting for drawer handover');
        $this->get(route('shifts.index'))->assertSee('Start cashier shift')->assertDontSee('Count and accept handover');

        $this->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 70])
            ->assertSessionHasNoErrors();
        $current = Shift::whereNull('closed_at')->sole();
        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])
            ->assertSessionHasNoErrors();

        $this->assertSame($original, $previous->refresh()->getAttributes());
        $this->assertSame('70.00', $current->opening_float);
        $this->assertNull($current->handover_from_id);
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'shift_id' => $current->id, 'user_id' => $incoming->id, 'cash_amount' => 395]);
    }

    public function test_closing_a_shift_retains_the_count_without_requiring_acceptance(): void
    {
        $cashier = $this->cashier();
        $shift = Shift::create(['opened_by' => $cashier->id, 'opened_at' => now()->subHour(),
            'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 100]);

        $this->actingAs($cashier)->post(route('shifts.close', $shift), [
            'denominations' => [100 => 1], 'incoming_user_id' => 999999, 'handoff_notes' => 'Count verified',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($shift->fresh()->closed_at);
        $this->assertSame('100.00', $shift->fresh()->cash_total);
        $this->assertSame('Count verified', $shift->fresh()->handoff_notes);
        $this->assertNull($shift->fresh()->handover_status);
        $this->assertNull($shift->fresh()->incoming_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'shift.closed', 'auditable_id' => $shift->id]);
        $this->get(route('shifts.remittance_slip', $shift))->assertSee('SHIFT DRAWER RECONCILIATION REPORT')->assertDontSee('Acceptance pending');
    }

    public function test_disabled_handover_endpoint_does_not_change_the_existing_record(): void
    {
        $cashier = $this->cashier();
        $shift = $this->closedShift($cashier, 'pending');
        $original = $shift->refresh()->getAttributes();

        $this->actingAs(User::where('email', 'raquel@sedonapms.com')->firstOrFail())
            ->post(route('shifts.accept', $shift), ['operation_key' => (string) Str::uuid(), 'accepted_cash' => 100, 'notes' => 'Old link'])
            ->assertNotFound();

        $this->assertSame($original, $shift->refresh()->getAttributes());
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    public function test_another_open_drawer_still_prevents_starting_a_second_shift(): void
    {
        $cashier = $this->cashier();
        $shift = Shift::create(['opened_by' => $cashier->id, 'opened_at' => now(),
            'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 100]);

        $this->actingAs(User::where('email', 'raquel@sedonapms.com')->firstOrFail())
            ->post(route('shifts.open'), ['operation_key' => (string) Str::uuid(), 'opening_float' => 100])
            ->assertSessionHasErrors(['shift' => 'There is already an open shift in progress.']);

        $this->assertDatabaseCount('shifts', 1);
        $this->assertNull($shift->fresh()->closed_at);
    }

    public function test_day_closing_ignores_old_handover_tasks_but_preserves_other_approval_checks(): void
    {
        $cashier = $this->cashier();
        $shift = $this->closedShift($cashier, 'disputed');
        $approval = ApprovalRequest::factory()->create(['kind' => 'handover_variance', 'source_type' => Shift::class,
            'source_id' => $shift->id, 'requested_by' => $cashier->id, 'payload' => []]);

        $snapshot = app(BusinessDayService::class)->snapshot('2026-10-12');

        $this->assertSame([], $snapshot['exceptions']);
        $this->assertFalse($snapshot['shift_handover_enabled']);
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertSame('disputed', $shift->fresh()->handover_status);

        ApprovalRequest::factory()->create(['requested_by' => $cashier->id]);
        $this->assertContains('An approval request is unresolved.', app(BusinessDayService::class)->snapshot('2026-10-12')['exceptions']);
    }

    public function test_old_handover_approval_cannot_be_requested_or_executed_while_disabled(): void
    {
        $cashier = $this->cashier();
        $shift = $this->closedShift($cashier, 'disputed');
        $approval = ApprovalRequest::factory()->create(['kind' => 'handover_variance', 'source_type' => Shift::class,
            'source_id' => $shift->id, 'requested_by' => $cashier->id, 'payload' => [], 'status' => 'approved',
            'reviewed_by' => User::where('email', 'owner@sedonapms.com')->firstOrFail()->id]);

        $this->actingAs($cashier)->post(route('approvals.store'), ['operation_key' => (string) Str::uuid(),
            'kind' => 'handover_variance', 'source_id' => $shift->id, 'reason' => 'Old handover'])->assertStatus(409);
        $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertStatus(409);

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame('disputed', $shift->fresh()->handover_status);
        $this->assertDatabaseCount('approval_requests', 1);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    private function cashier(): User
    {
        $this->withoutVite();
        $this->travelTo('2026-10-12 12:00:00');
        $this->seed(DatabaseSeeder::class);

        return User::where('email', 'pau@sedonapms.com')->firstOrFail();
    }

    private function closedShift(User $cashier, string $status): Shift
    {
        return Shift::create(['opened_by' => $cashier->id, 'closed_by' => $cashier->id,
            'opened_at' => now()->subHours(3), 'closed_at' => now()->subHours(2),
            'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 100,
            'cash_total' => 100, 'expected_cash' => 100, 'cash_variance' => 0, 'handover_status' => $status]);
    }
}
