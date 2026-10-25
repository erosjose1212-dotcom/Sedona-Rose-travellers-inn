<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillingPrintTimeLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-17 12:00:00');
    }

    public function test_preview_does_not_issue_and_print_request_records_first_print_once_even_if_retried(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $payload = $this->key();
        $this->actingAs($actor)->get(route('folios.billing', $folio))->assertOk()
            ->assertSee(route('folios.billing.print', $folio), false)->assertDontSee('class="billing-print-authorized"', false);
        $this->assertNull($folio->fresh()->billing_printed_at);

        $this->post(route('folios.billing.print', $folio), $payload)->assertOk()->assertViewHas('printRequested', true)
            ->assertSee('class="billing-print-authorized"', false);
        $this->post(route('folios.billing.print', $folio), $payload)->assertOk();

        $this->assertSame('2026-10-17 12:00:00', $folio->fresh()->billing_printed_at->toDateTimeString());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'billing.print_requested')->count());
        $audit = DB::table('audit_logs')->where('event', 'billing.print_requested')->first();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertFalse(json_decode($audit->new_values, true)['physical_print_confirmed']);
        $this->assertNull($folio->fresh()->receipt_number);
        $this->travel(2)->minutes();
        $this->post(route('folios.billing.print', $folio), $this->key())->assertOk()->assertViewHas('receiptCopy', 2);
        $this->assertSame('2026-10-17 12:00:00', $folio->fresh()->billing_printed_at->toDateTimeString());
    }

    #[TestWith(['cashier'])]
    #[TestWith(['front_desk'])]
    #[TestWith(['manager'])]
    public function test_staff_can_extend_and_convert_a_printed_stay(string $role): void
    {
        $actor = $this->actor($role);
        $folio = $this->stay($actor);
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), $this->key())->assertOk();
        $this->post(route('folios.extend', $folio), $this->key() + ['hours' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $folio->fresh()->extra_hours);
        foreach (['12h', '24h'] as $tier) {
            $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => $tier, 'reason' => 'Guest requested conversion'])->assertSessionHasNoErrors();
            $this->assertSame($tier, $folio->fresh()->rate_tier);
        }

        $this->assertNotNull($folio->fresh()->billing_printed_at);
    }

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_admin_and_owner_can_correct_extend_and_convert_after_staff_prints(string $role): void
    {
        $admin = $this->actor($role);
        $folio = $this->stay($admin);
        $data = $this->key() + ['checked_in_at' => '2026-10-17T10:30', 'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(), 'reason' => 'RA declared earlier'];
        $cashier = $this->actor('cashier');
        $this->actingAs($cashier)->post(route('folios.billing.print', $folio), $this->key())->assertOk();
        $printedAt = $folio->fresh()->billing_printed_at->toDateTimeString();

        $this->actingAs($admin)->get(route('admin.check-in-times.index'))->assertOk()->assertSee('name="checked_in_at"', false);
        $this->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn ($options): bool => count($options[$folio->id]['conversions']) === 2);
        $this->post(route('admin.check-in-times.update', $folio), $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-17 10:30:00', $folio->fresh()->checked_in_at->toDateTimeString());
        $this->post(route('folios.extend', $folio), $this->key() + ['hours' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $folio->fresh()->extra_hours);
        foreach (['12h', '24h'] as $tier) {
            $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => $tier, 'reason' => 'Guest requested conversion'])->assertSessionHasNoErrors();
            $this->assertSame($tier, $folio->fresh()->rate_tier);
        }

        $this->assertSame($printedAt, $folio->fresh()->billing_printed_at->toDateTimeString());
        foreach (['folio.checkin_time_corrected', 'folio.extended', 'folio.package_converted'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'user_id' => $admin->id, 'auditable_id' => $folio->id]);
        }
        $this->actingAs($cashier)->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn ($options): bool => $options[$folio->id]['conversions'] === []);
        $this->post(route('folios.extend', $folio), $this->key() + ['hours' => 1, 'role' => 'admin'])->assertSessionHasNoErrors();
    }

    public function test_open_time_can_convert_after_printing(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $folio->update(['stay_mode' => 'open']);
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), $this->key())->assertOk();

        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Change Open Time'])->assertSessionHasNoErrors();

        $this->assertSame('fixed', $folio->fresh()->stay_mode);
    }

    public function test_deposit_collection_and_checkout_keep_working_with_normal_overstay_billing(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        Shift::create(['opened_by' => $actor->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now(), 'opening_float' => 0]);
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), $this->key())->assertOk();
        $this->post(route('folios.deposit.collect', $folio), $this->key() + ['amount' => 100, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->travelTo('2026-10-17 16:10:00');

        $this->post(route('checkout.submit', $folio), $this->key() + ['payment_method' => 'cash', 'cash_tendered' => 1000])->assertSessionHasNoErrors();

        $folio->refresh();
        $this->assertSame('checked_out', $folio->status);
        $this->assertSame(2, $folio->overtime_hours);
        $this->assertSame('655.00', $folio->gross_total);
        $this->assertSame('555.00', $folio->net_total);
        $this->assertSame('2026-10-17 16:10:00', $folio->checked_out_at->toDateTimeString());
        $this->assertNotNull($folio->receipt_number);
    }

    public function test_print_validation_and_audit_failure_do_not_record_a_print(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), [])->assertSessionHasErrors('operation_key');
        $this->post(route('folios.billing.print', $folio), $this->key() + ['room_charge' => 1])->assertSessionHasErrors('room_charge');
        $this->assertNull($folio->fresh()->billing_printed_at);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "audit_logs"')) {
                throw new RuntimeException('Simulated print audit failure.');
            }
        });
        try {
            $this->post(route('folios.billing.print', $folio), $this->key())->assertServerError();
        } finally {
            Event::forget(QueryExecuted::class);
        }
        $this->assertNull($folio->fresh()->billing_printed_at);
        $this->assertDatabaseCount('financial_operations', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'billing.print_requested')->count());
    }

    public function test_invalid_accounts_and_cancelled_stays_cannot_issue_a_billing_print(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $kitchen = User::factory()->create()->assignRole(Role::findOrCreate('kitchen', 'web'));
        $this->actingAs($kitchen)->post(route('folios.billing.print', $folio), $this->key())->assertForbidden();
        $folio->update(['status' => 'voided']);
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), $this->key())->assertConflict();
        $this->assertNull($folio->fresh()->billing_printed_at);
    }

    public function test_stale_print_parameters_redirect_to_a_clean_preview_with_an_error(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $staleUrl = route('folios.billing', $folio).'?room_charge=1';

        $this->actingAs($actor)->from($staleUrl)->post(route('folios.billing.print', $folio).'?room_charge=1', $this->key())
            ->assertRedirect(route('folios.billing', $folio))->assertSessionHasErrors('room_charge');

        $this->withCookie(config('session.cookie'), session()->getId())->get(route('folios.billing', $folio))
            ->assertOk()->assertSee('Statement was not printed.');
        $this->assertNull($folio->fresh()->billing_printed_at);
    }

    public function test_retry_of_an_extension_completed_before_print_does_not_add_hours_twice(): void
    {
        $actor = $this->actor('cashier');
        $folio = $this->stay($actor);
        $data = $this->key() + ['hours' => 1];
        $this->actingAs($actor)->post(route('folios.extend', $folio), $data)->assertSessionHasNoErrors();
        $this->post(route('folios.billing.print', $folio), $this->key())->assertOk();

        $this->post(route('folios.extend', $folio), $data)->assertSessionHasNoErrors();

        $this->assertSame(1, $folio->fresh()->extra_hours);
        $this->assertSame('2026-10-17 15:00:00', $folio->fresh()->expected_checkout_at->toDateTimeString());
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'view_dashboard', 'record_payments', 'manage_shifts'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    }

    private function stay(User $actor): Folio
    {
        $room = Room::create(['number' => '1', 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'occupied', 'base_rate_3h' => 395, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100]);

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Print guest', 'headcount' => 2])->id,
            'user_id' => $actor->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'status' => 'active', 'room_charge' => 395,
            'checked_in_at' => '2026-10-17 11:00:00', 'expected_checkout_at' => '2026-10-17 14:00:00']);
    }

    /** @return array{operation_key: string} */
    private function key(): array
    {
        return ['operation_key' => (string) Str::uuid()];
    }
}
