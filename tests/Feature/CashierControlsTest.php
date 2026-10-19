<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_checkout_without_a_shift_keeps_the_folio_and_room_open(): void
    {
        $cashier = $this->operator();
        $folio = $this->folio($cashier);

        $this->actingAs($cashier)->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])
            ->assertSessionHasErrors(['shift' => 'Start your cashier shift before recording payments.']);

        $this->assertSame('active', $folio->fresh()->status);
        $this->assertSame('occupied', $folio->room->fresh()->status);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_deposit_without_a_shift_does_not_record_a_collection(): void
    {
        $cashier = $this->operator();
        $folio = $this->folio($cashier);

        $this->actingAs($cashier)->postJson(route('folios.deposit.collect', $folio), ['operation_key' => (string) Str::uuid(), 'amount' => 100, 'payment_method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('shift');

        $this->assertSame('0.00', $folio->fresh()->security_deposit);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_walkin_payment_requires_an_open_unfrozen_shift_and_rolls_back_stock(bool $frozen): void
    {
        $cashier = $this->operator();
        $item = $this->item();
        if ($frozen) {
            $this->shift($cashier, ['is_frozen' => true]);
        }

        $this->actingAs($cashier)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()] + $this->sale($item))->assertSessionHasErrors('shift');

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_events', 0);
    }

    public function test_cashier_cannot_pay_or_close_another_cashiers_drawer(): void
    {
        $assigned = $this->operator();
        $other = $this->operator('raquel@sedonapms.com');
        $shift = $this->shift($assigned);
        $item = $this->item();

        $this->actingAs($other)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()] + $this->sale($item))->assertForbidden();
        $this->post(route('shifts.close', $shift), ['denominations' => ['coins' => 100]])->assertForbidden();
        $this->post(route('shifts.expense.store'), ['operation_key' => (string) Str::uuid(), 'category' => 'supplies', 'description' => 'Cash out', 'amount' => 10])->assertForbidden();

        $this->assertNull($shift->fresh()->closed_at);
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_cashier_can_settle_a_shared_guest_folio_using_their_own_drawer(): void
    {
        $assigned = $this->operator();
        $folio = $this->folio($this->operator('raquel@sedonapms.com'));
        $shift = $this->shift($assigned);

        $this->actingAs($assigned)->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 400])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertSame('checked_out', $folio->fresh()->status);
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'shift_id' => $shift->id,
            'user_id' => $assigned->id, 'kind' => 'settlement', 'cash_amount' => 395]);
    }

    public function test_admin_override_requires_a_reason_and_records_it_with_the_payment(): void
    {
        $shift = $this->shift($this->operator());
        $admin = $this->operator('admin1@sedonapms.com');
        $item = $this->item();

        $this->actingAs($admin)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()] + $this->sale($item))->assertSessionHasErrors('shift_override_reason');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()] + $this->sale($item) + ['shift_override_reason' => 'Covering while the cashier assists a guest'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('orders', ['user_id' => $admin->id, 'shift_id' => $shift->id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'shift.override', 'auditable_id' => $shift->id]);
        $audit = DB::table('audit_logs')->first();
        $this->assertSame(['action' => 'pos_sale', 'reason' => 'Covering while the cashier assists a guest'], json_decode($audit->new_values, true));
    }

    public function test_owner_override_close_is_counted_once_and_audited(): void
    {
        $shift = $this->shift($this->operator());
        $owner = $this->operator('owner@sedonapms.com');

        $this->actingAs($owner)->post(route('shifts.close', $shift), ['denominations' => ['coins' => 100], 'shift_override_reason' => 'Cashier emergency departure'])
            ->assertSessionHasNoErrors();
        $this->post(route('shifts.close', $shift), ['denominations' => ['coins' => 0], 'shift_override_reason' => 'Retry'])
            ->assertSessionHasErrors('shift');

        $this->assertSame($owner->id, $shift->fresh()->closed_by);
        $this->assertSame('100.00', $shift->fresh()->cash_total);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'shift.closed', 'auditable_id' => $shift->id]);
    }

    public function test_failed_overridden_sale_rolls_back_the_audit_entry_and_stock(): void
    {
        $this->shift($this->operator());
        $item = $this->item();
        Order::creating(function (): void {
            throw new RuntimeException('Simulated sale storage failure.');
        });

        try {
            $this->actingAs($this->operator('admin1@sedonapms.com'))->post(route('pos.order.store'),
                ['operation_key' => (string) Str::uuid()] + $this->sale($item) + ['shift_override_reason' => 'Covering the assigned cashier'])->assertInternalServerError();
        } finally {
            Event::forget('eloquent.creating: '.Order::class);
        }

        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_refund_without_an_operating_shift_does_not_reduce_the_remaining_deposit(): void
    {
        $cashier = $this->operator();
        $folio = $this->folio($cashier);
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now(), 'security_deposit' => 100, 'deposit_status' => 'not_applied']);

        $this->actingAs($cashier)->post(route('folios.deposit_refund.store', $folio), ['operation_key' => (string) Str::uuid(), 'amount' => 25, 'payment_method' => 'cash'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $approval = $this->approveLatestFinancialRequest();
        $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('shift');

        $this->assertSame('0.00', $folio->fresh()->deposit_refunded_amount);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_closed_shift_participant_can_read_their_slip_without_receiving_drawer_custody(): void
    {
        $cashier = $this->operator();
        $shift = $this->shift($this->operator('raquel@sedonapms.com'), ['closed_at' => now(), 'handoff_notes' => 'Participated shift']);
        $folio = $this->folio($cashier);
        PaymentEntry::create(['folio_id' => $folio->id, 'shift_id' => $shift->id, 'user_id' => $cashier->id,
            'kind' => 'settlement', 'cash_amount' => 50, 'gcash_amount' => 0, 'posted_at' => now()]);

        $this->actingAs($cashier)->get(route('shifts.index'))->assertSee('Participated shift');
        $this->get(route('shifts.remittance_slip', $shift))->assertOk();
        $this->assertFalse(Gate::forUser($cashier)->allows('operate-shift', $shift));
    }

    public function test_manager_permissions_cannot_grant_an_admin_drawer_override(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $manager->givePermissionTo(['manage_any_shifts', 'view_all_shifts']);
        $shift = $this->shift($this->operator());

        $this->actingAs($manager)->post(route('shifts.close', $shift), ['denominations' => ['coins' => 100], 'shift_override_reason' => 'Attempted override'])
            ->assertForbidden();

        $this->assertNull($shift->fresh()->closed_at);
        $this->assertFalse(Gate::forUser($manager)->allows('view-shift', $shift));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_past_participation_does_not_expose_another_cashiers_live_drawer(): void
    {
        $cashier = $this->operator();
        $shift = $this->shift($this->operator('raquel@sedonapms.com'));
        $folio = $this->folio($cashier);
        PaymentEntry::create(['folio_id' => $folio->id, 'shift_id' => $shift->id, 'user_id' => $cashier->id,
            'kind' => 'deposit', 'cash_amount' => 50, 'gcash_amount' => 0, 'posted_at' => now()]);

        $this->actingAs($cashier)->get(route('shifts.remittance_slip', $shift))->assertForbidden();
        $this->post(route('shifts.close', $shift))->assertForbidden();

        $this->assertNull($shift->fresh()->closed_at);
    }

    public function test_kitchen_can_update_order_status_but_cannot_post_sales(): void
    {
        $cashier = $this->operator();
        $order = Order::create(['user_id' => $cashier->id, 'transaction_id' => 'KITCHEN-ONLY', 'type' => 'walkin_pos', 'status' => 'new', 'total' => 60]);
        $kitchen = $this->operator('kitchen1@sedonapms.com');

        $this->actingAs($kitchen)->post(route('pos.order.store'), ['operation_key' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('pos.index'))->assertForbidden();
        $this->get(route('kitchen.view'))->assertOk();
        $this->post(route('pos.order.status', $order), ['status' => 'preparing'])->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_folio_access_alone_does_not_grant_payment_posting(): void
    {
        $cashier = $this->operator();
        $cashier->roles->first()->revokePermissionTo('record_payments');
        $folio = $this->folio($cashier);
        $this->shift($cashier);

        $this->actingAs($cashier->fresh())->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])->assertForbidden();

        $this->assertSame('active', $folio->fresh()->status);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_cashier_shift_history_and_slips_are_scoped_but_admin_can_review_all(): void
    {
        $cashier = $this->operator();
        $own = $this->shift($cashier, ['closed_at' => now(), 'handoff_notes' => 'Own closing note']);
        $other = $this->shift($this->operator('raquel@sedonapms.com'), ['closed_at' => now(), 'handoff_notes' => 'Private closing note']);

        $this->actingAs($cashier)->get(route('shifts.index'))->assertSee('Own closing note')->assertDontSee('Private closing note');
        $this->get(route('shifts.remittance_slip', $other))->assertForbidden();
        $this->get(route('shifts.remittance_slip', $own))->assertOk();
        $this->actingAs($this->operator('admin1@sedonapms.com'))->get(route('shifts.index'))->assertSee('Private closing note');
    }

    public function test_other_cashier_sees_custodian_without_active_drawer_totals(): void
    {
        $this->shift($this->operator(), ['opening_float' => 12345.67]);

        $this->actingAs($this->operator('raquel@sedonapms.com'))->get(route('shifts.index'))
            ->assertSee('Drawer in use by Pau')->assertDontSee('12,345.67')->assertDontSee('Close Shift & Record Count');
    }

    public function test_live_gcash_and_cash_variance_use_collections_and_the_recorded_count(): void
    {
        $cashier = $this->operator();
        $shift = $this->shift($cashier);
        $folio = $this->folio($cashier);
        PaymentEntry::create(['folio_id' => $folio->id, 'shift_id' => $shift->id, 'user_id' => $cashier->id,
            'kind' => 'deposit', 'cash_amount' => 0, 'gcash_amount' => 250, 'posted_at' => now()]);

        $this->actingAs($cashier)->get(route('shifts.index'))->assertViewHas('gcashCollections', 250.0)->assertSee('250.00');
        $this->post(route('shifts.close', $shift), ['denominations' => ['coins' => 100]])->assertSessionHasNoErrors();
        $this->actingAs($this->operator('admin1@sedonapms.com'))->get(route('admin.accounting.shifts'))
            ->assertSee('Balanced')->assertDontSee('(Over)');

        $this->assertSame('250.00', $shift->fresh()->gcash_total);
        $this->assertSame('0.00', $shift->fresh()->cash_variance);
        $this->assertSame('0.00', $shift->fresh()->gross_revenue);
    }

    public function test_permission_upgrade_keeps_kitchen_status_separate_and_respects_revoked_access(): void
    {
        $kitchen = Role::findByName('kitchen');
        $kitchen->givePermissionTo('manage_pos');
        Role::findByName('cashier')->revokePermissionTo('manage_pos');
        $migration = require database_path('migrations/2026_10_08_093024_add_cashier_operation_permissions.php');
        $migration->down();

        $migration->up();

        $this->assertTrue($kitchen->fresh()->hasPermissionTo('update_kitchen_status'));
        $this->assertFalse($kitchen->fresh()->hasPermissionTo('post_pos_sales'));
        $this->assertFalse(Role::findByName('cashier')->hasPermissionTo('post_pos_sales'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('manage_any_shifts'));
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function shiftActors(): array
    {
        return [
            'assigned cashier' => ['pau@sedonapms.com', true, true],
            'other cashier' => ['raquel@sedonapms.com', false, false],
            'admin override' => ['admin1@sedonapms.com', false, true],
            'owner override' => ['owner@sedonapms.com', false, true],
            'kitchen has no custody permission' => ['kitchen1@sedonapms.com', true, false],
        ];
    }

    #[DataProvider('shiftActors')]
    public function test_shift_authority_matches_custody_and_role(string $email, bool $ownsShift, bool $allowed): void
    {
        $actor = $this->operator($email);
        $shift = $this->shift($ownsShift ? $actor : $this->operator());

        $this->assertSame($allowed, Gate::forUser($actor)->allows('operate-shift', $shift));
    }

    private function operator(string $email = 'pau@sedonapms.com'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function shift(User $cashier, array $attributes = []): Shift
    {
        return Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => today(),
            'opened_at' => now(), 'opening_float' => 100, ...$attributes]);
    }

    private function folio(User $cashier): Folio
    {
        $room = Room::where('number', '1')->firstOrFail();
        $room->update(['status' => 'occupied']);

        return Folio::create(['user_id' => $cashier->id, 'room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Cashier Controls Guest'])->id,
            'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(3), 'room_charge' => 395, 'status' => 'active']);
    }

    private function item(): PosItem
    {
        return PosItem::create(['name' => 'Control Test Drink', 'category' => 'Drinks', 'price' => 60, 'stock_quantity' => 10,
            'is_tracked' => true, 'is_available' => true, 'kitchen_hours_only' => false]);
    }

    /** @return array<string, mixed> */
    private function sale(PosItem $item): array
    {
        return ['order_type' => 'walkin_pos', 'payment_method' => 'cash', 'cash_tendered' => 60, 'items' => [['id' => $item->id, 'qty' => 1]]];
    }
}
