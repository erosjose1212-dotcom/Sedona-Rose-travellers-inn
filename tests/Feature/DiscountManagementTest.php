<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\DiscountRate;
use App\Models\Folio;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BillingService;
use App\Services\DiscountService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DiscountRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DiscountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-10 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $this->admin = User::where('email', 'admin1@sedonapms.com')->firstOrFail();
        $this->cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
    }

    #[TestWith(['owner'])]
    #[TestWith(['admin'])]
    public function test_property_accounts_can_view_and_update_defaults_with_an_audit_record(string $role): void
    {
        $actor = $role === 'owner' ? $this->owner : $this->admin;
        $this->actingAs($actor)->get(route('admin.discounts.index'))->assertOk()
            ->assertSee('Default discounts for future guests')->assertSee('Senior Citizen / PWD');
        $this->post(route('admin.discounts.update'), $this->defaults())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('discount_rates', ['discount_type' => 'SENIOR', 'room_tier' => 'CLASSIC', 'duration' => '3h', 'amount' => 90.5, 'updated_by' => $actor->id]);
        $this->assertSame(90.5, DiscountService::getDiscountAmount('PWD', 'Classic Room', '3h'));
        $audit = DB::table('audit_logs')->where('event', 'discount.schedule_updated')->sole();
        $details = json_decode($audit->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('79.00', $details['before']['3h']);
        $this->assertSame('90.50', $details['after']['3h']);
        $this->assertSame('Updated property policy', $details['reason']);
        $this->seed(DiscountRateSeeder::class);
        $this->assertDatabaseCount('discount_rates', 24);
        $this->assertDatabaseHas('discount_rates', ['discount_type' => 'SENIOR', 'room_tier' => 'CLASSIC', 'duration' => '3h', 'amount' => 90.5]);
    }

    #[TestWith(['cashier'])]
    #[TestWith(['front_desk'])]
    #[TestWith(['manager'])]
    #[TestWith(['kitchen'])]
    #[TestWith(['customer_display'])]
    public function test_other_roles_cannot_view_or_edit_discount_defaults(string $role): void
    {
        $actor = User::factory()->create();
        $actor->assignRole($role);
        $this->actingAs($actor)->get(route('admin.discounts.index'))->assertForbidden();
        $this->post(route('admin.discounts.update'), $this->defaults())->assertForbidden();
        $this->assertSame(79.0, DiscountService::getDiscountAmount('SENIOR', 'Classic Room', '3h'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'discount.schedule_updated']);
    }

    public function test_guests_must_sign_in_before_accessing_discount_settings(): void
    {
        $this->get(route('admin.discounts.index'))->assertRedirect(route('login'));
        $this->post(route('admin.discounts.update'), $this->defaults())->assertRedirect(route('login'));
    }

    public function test_active_guest_editor_displays_the_current_discount_and_the_review_steps(): void
    {
        $folio = $this->checkIn('6', true);
        $this->actingAs($this->owner)->get(route('admin.discounts.index'))->assertOk()
            ->assertSee('Room 6')->assertSee('Guest room 6')->assertSee('₱79.00 discount')
            ->assertSee('Apply approved action')->assertSee('Request discount change for Room 6')
            ->assertSee('name="source_id" value="'.$folio->id.'"', false);
    }

    #[TestWith(['amounts', ['3h' => -1, '6h' => 158, '12h' => 195, '24h' => 340], 'amounts.3h'])]
    #[TestWith(['amounts', ['3h' => 1.234, '6h' => 158, '12h' => 195, '24h' => 340], 'amounts.3h'])]
    #[TestWith(['amounts', ['3h' => 90], 'amounts.6h'])]
    #[TestWith(['discount_type', 'PWD', 'discount_type'])]
    #[TestWith(['room_tier', 'OTHER', 'room_tier'])]
    #[TestWith(['reason', '', 'reason'])]
    public function test_invalid_defaults_do_not_change_any_rate(string $field, mixed $value, string $error): void
    {
        $before = DiscountRate::orderBy('id')->get()->toArray();
        $this->actingAs($this->owner)->post(route('admin.discounts.update'), array_replace($this->defaults(), [$field => $value]))->assertSessionHasErrors($error);
        $this->assertSame($before, DiscountRate::orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'discount.schedule_updated']);
    }

    public function test_default_changes_preserve_active_and_completed_bills_and_apply_to_future_checkins(): void
    {
        $active = $this->checkIn('6', true);
        $completed = $this->checkIn('7', true);
        Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => now(), 'shift_date' => today(), 'shift_type' => 'day']);
        $this->post(route('checkout.submit', $completed), ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 316])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('checked_out', $completed->fresh()->status);
        $before = $completed->fresh()->only(['gross_total', 'discount_amount', 'net_total']);

        $this->actingAs($this->owner)->post(route('admin.discounts.update'), $this->defaults())->assertSessionHasNoErrors();
        $this->assertEquals(79.0, app(BillingService::class)->calculate($active->fresh())['totalDiscount']);
        $this->assertSame($before, $completed->fresh()->only(['gross_total', 'discount_amount', 'net_total']));
        $this->assertEquals(79.0, app(BillingService::class)->calculate($completed->fresh())['totalDiscount']);
        $this->get(route('checkout.process', $active))->assertOk()->assertViewHas('discountOptions', fn (array $options): bool => $options['SENIOR'] === 79.0);
        $future = $this->checkIn('8', true);
        $this->assertSame(90.5, (float) $future->discount_amount);
        $this->assertSame(304.5, (float) $future->net_total);
        $this->actingAs($this->cashier)->get(route('dashboard'))->assertOk()
            ->assertViewHas('folioDiscountOptions', fn (array $options): bool => $options[$active->id]['SENIOR'] === 79.0 && $options[$future->id]['SENIOR'] === 90.5);
    }

    public function test_upgrade_snapshots_existing_active_guests_before_defaults_can_change(): void
    {
        $folio = $this->checkIn('6', true);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $migration = require database_path('migrations/2026_10_10_221851_add_discount_snapshot_to_folios_table.php');
        $migration->down();
        $migration->up();
        $this->actingAs($this->owner)->post(route('admin.discounts.update'), $this->defaults())->assertSessionHasNoErrors();
        $this->assertEquals(79.0, app(BillingService::class)->calculate($folio->fresh())['totalDiscount']);
        $this->assertSame(79, $folio->fresh()->discount_schedule['SENIOR']['CLASSIC']['3h']);
    }

    #[TestWith(['owner', 'DC', null])]
    #[TestWith(['admin', 'SENIOR', 'OSCA-EDIT-1'])]
    #[TestWith(['owner', 'PWD', 'PWD-EDIT-1'])]
    #[TestWith(['owner', 'DC', null, 0.0])]
    public function test_individual_discount_edit_respects_owner_authority_and_updates_only_the_selected_guest(string $role, string $type, ?string $reference, float $amount = 100.25): void
    {
        $folio = $this->checkIn('6');
        $other = $this->checkIn('7');
        $actor = $role === 'owner' ? $this->owner : $this->admin;
        $reviewer = $role === 'owner' ? $this->admin : $this->owner;
        $this->actingAs($actor);
        $approval = $this->requestDiscount($folio, ['discount_type' => $type, 'discount_id_ref' => $reference, 'discount_override_amount' => $amount]);
        $this->assertSame('NONE', $folio->fresh()->discount_type);
        if ($role === 'owner') {
            $this->assertSame('approved', $approval->status);
            $this->assertSame($actor->id, $approval->reviewed_by);
        } else {
            $this->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Self review'])->assertForbidden();
            $this->actingAs($reviewer)->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Verified correction'])->assertSessionHasNoErrors();
        }
        $this->actingAs($actor)->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertSame($type, $folio->discount_type);
        $this->assertSame(number_format($amount, 2, '.', ''), $folio->discount_override_amount);
        $this->assertSame($amount, (float) $folio->discount_amount);
        $this->assertSame(395.0 - $amount, (float) $folio->net_total);
        $this->assertSame(in_array($type, ['SENIOR', 'PWD'], true), $folio->senior_pwd_discount);
        $this->assertSame('NONE', $other->fresh()->discount_type);
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval.executed', 'auditable_id' => $approval->id]);
        $this->assertSame(79.0, DiscountService::getDiscountAmount('SENIOR', 'Classic Room', '3h'));
        $this->get(route('checkout.process', $folio))->assertOk()->assertViewHas('discountOptions', fn (array $options): bool => $options[$type] === $amount);
        $this->get(route('folios.billing', $folio))->assertOk()->assertViewHas('totalDiscount', $amount);
    }

    public function test_cashier_settlement_and_receipt_keep_the_approved_custom_amount(): void
    {
        $folio = $this->checkIn('6');
        $this->actingAs($this->owner);
        $this->requestDiscount($folio, ['discount_type' => 'DC', 'discount_override_amount' => 100.25]);
        $this->executeLatestFinancialRequest();
        Shift::create(['opened_by' => $this->cashier->id, 'opened_at' => now(), 'shift_date' => today(), 'shift_type' => 'day']);
        $this->actingAs($this->cashier)->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 300,
            'discount_type' => 'dc'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'status' => 'checked_out', 'discount_amount' => 100.25, 'net_total' => 294.75, 'change_due' => 5.25]);
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'kind' => 'settlement', 'cash_amount' => 294.75]);
        $this->get(route('folios.receipt', $folio))->assertOk()->assertViewHas('totalDiscount', 100.25)->assertViewHas('finalBalanceToPay', 294.75);
    }

    public function test_cashier_cannot_request_or_submit_an_unapproved_custom_amount(): void
    {
        $folio = $this->checkIn('6');
        $this->post(route('approvals.store'), $this->discountRequest($folio, ['discount_type' => 'DC', 'discount_override_amount' => 100]))->assertForbidden();
        $this->post(route('folios.discount', $folio), ['discount_type' => 'senior', 'discount_id_ref' => 'OSCA-1', 'discount_override_amount' => 100])->assertSessionHasNoErrors();
        $this->assertNull($folio->fresh()->discount_override_amount);
        $this->assertSame(79.0, (float) $folio->fresh()->discount_amount);
    }

    #[TestWith(['SENIOR', null, 100, 'discount_id_ref'])]
    #[TestWith(['PWD', ' ', 100, 'discount_id_ref'])]
    #[TestWith(['DC', null, -1, 'discount_override_amount'])]
    #[TestWith(['DC', null, 1.234, 'discount_override_amount'])]
    #[TestWith(['DC', null, 396, 'discount_override_amount'])]
    #[TestWith(['NONE', null, 100, 'discount_override_amount'])]
    public function test_invalid_individual_corrections_are_rejected(string $type, ?string $reference, float $amount, string $error): void
    {
        $folio = $this->checkIn('6');
        $this->actingAs($this->owner)->post(route('approvals.store'), $this->discountRequest($folio, ['discount_type' => $type, 'discount_id_ref' => $reference, 'discount_override_amount' => $amount]))->assertSessionHasErrors($error);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertSame('NONE', $folio->fresh()->discount_type);
    }

    public function test_an_approved_custom_discount_can_be_reset_to_its_recorded_default(): void
    {
        $folio = $this->checkIn('6', true);
        $folio->update(['discount_override_amount' => 100]);
        $folio->recalculate();
        $this->actingAs($this->owner)->post(route('admin.discounts.update'), $this->defaults())->assertSessionHasNoErrors();
        $this->requestDiscount($folio, ['discount_type' => 'SENIOR', 'discount_override_amount' => null]);
        $this->executeLatestFinancialRequest();
        $this->assertNull($folio->fresh()->discount_override_amount);
        $this->assertSame(79.0, (float) $folio->fresh()->discount_amount);
        $this->assertSame('OSCA-TEST', $folio->fresh()->discount_id_ref);
    }

    public function test_removing_a_discount_clears_its_custom_amount_and_id_reference(): void
    {
        $folio = $this->checkIn('6', true);
        $folio->update(['discount_override_amount' => 100]);
        $this->actingAs($this->owner);
        $this->requestDiscount($folio, ['discount_type' => 'NONE']);
        $this->executeLatestFinancialRequest();
        $this->assertNull($folio->fresh()->discount_override_amount);
        $this->assertNull($folio->fresh()->discount_id_ref);
        $this->assertFalse($folio->fresh()->senior_pwd_discount);
        $this->assertSame(0.0, (float) $folio->fresh()->discount_amount);
    }

    public function test_cashier_cannot_change_the_type_of_an_approved_custom_discount(): void
    {
        $folio = $this->checkIn('6', true);
        $folio->update(['discount_override_amount' => 100]);
        $this->post(route('folios.discount', $folio), ['discount_type' => 'none'])->assertSessionHasErrors('discount_type');
        $this->assertSame('SENIOR', $folio->fresh()->discount_type);
        $this->assertSame('100.00', $folio->fresh()->discount_override_amount);
    }

    public function test_a_changed_discount_invalidates_the_pending_approval(): void
    {
        $folio = $this->checkIn('6', true);
        $this->actingAs($this->admin);
        $approval = $this->requestDiscount($folio, ['discount_type' => 'SENIOR', 'discount_override_amount' => 100]);
        $folio->update(['discount_id_ref' => 'CHANGED-ID']);
        $this->actingAs($this->owner)->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Stale review'])->assertSessionHasErrors('approval');
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_settled_bills_cannot_be_corrected_and_do_not_appear_in_the_editor(): void
    {
        $folio = $this->checkIn('6');
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $this->actingAs($this->owner)->post(route('approvals.store'), $this->discountRequest($folio, ['discount_type' => 'DC', 'discount_override_amount' => 50]))->assertStatus(409);
        $this->get(route('admin.discounts.index'))->assertOk()->assertSee('No active guest bills to edit.');
        $this->assertDatabaseCount('approval_requests', 0);
    }

    private function checkIn(string $roomNumber, bool $senior = false): Folio
    {
        $room = Room::where('number', $roomNumber)->firstOrFail();
        $this->actingAs($this->cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'guest_name' => 'Guest room '.$roomNumber,
            'rate_tier' => '3h', 'headcount' => 1, 'is_senior' => $senior, 'discount_id_ref' => $senior ? 'OSCA-TEST' : null])->assertRedirect()->assertSessionHasNoErrors();

        return Folio::where('room_id', $room->id)->where('status', 'active')->sole();
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ['discount_type' => 'SENIOR', 'room_tier' => 'CLASSIC', 'amounts' => ['3h' => 90.5, '6h' => 158, '12h' => 195, '24h' => 340], 'reason' => 'Updated property policy'];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function discountRequest(Folio $folio, array $payload): array
    {
        return ['operation_key' => (string) Str::uuid(), 'kind' => 'folio_override', 'source_id' => $folio->id, 'payload' => $payload, 'reason' => 'Correct guest discount', 'evidence_reference' => 'APPROVAL-NOTE-1'];
    }

    /** @param array<string, mixed> $payload */
    private function requestDiscount(Folio $folio, array $payload): ApprovalRequest
    {
        $this->post(route('approvals.store'), $this->discountRequest($folio, $payload))->assertRedirect()->assertSessionHasNoErrors();

        return ApprovalRequest::latest('id')->firstOrFail();
    }
}
