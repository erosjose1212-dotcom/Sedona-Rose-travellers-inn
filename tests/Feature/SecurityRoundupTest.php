<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\InventoryEvent;
use App\Models\PosItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SecurityRoundupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_owner_password_reset_revokes_old_database_sessions_and_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $cashier->forceFill(['remember_token' => Str::random(60)])->save();
        foreach (['cashier-one' => $cashier->id, 'cashier-two' => $cashier->id, 'owner-device' => $owner->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode('{}'), 'last_activity' => now()->timestamp]);
        }

        $this->actingAs($owner)->post(route('admin.users.reset-password', $cashier), ['password' => 'Replacement-password-672!'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Replacement-password-672!', $cashier->fresh()->password));
        $this->assertNull($cashier->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $cashier->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'owner-device', 'user_id' => $owner->id]);
    }

    public function test_a_retained_authenticated_session_is_rejected_after_its_password_changes(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $this->actingAs($cashier)->get(route('pos.index'))->assertOk();
        $cashier->update(['password' => 'Replacement-password-673!']);

        $this->actingAs($cashier->fresh())->get(route('pos.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_real_login_establishes_a_password_guard_before_first_protected_request(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $cashier->update(['password' => 'Login-password-672!']);
        $this->post(route('login.submit'), ['email' => $cashier->email, 'password' => 'Login-password-672!'])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $cashier->update(['password' => 'Replacement-password-674!']);
        $this->app['auth']->forgetGuards();

        $this->get(route('pos.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_cashier_cannot_reset_another_staff_password(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $target = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $before = $target->password;

        $this->actingAs($cashier)->post(route('admin.users.reset-password', $target), ['password' => 'Forbidden-password-672!'])->assertForbidden();

        $this->assertSame($before, $target->fresh()->password);
    }

    public function test_owner_account_changes_are_audited_without_credentials_hashes_or_tokens(): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $this->actingAs($owner)->post(route('admin.users.store'), ['name' => 'Audited cashier', 'email' => 'audited.cashier@example.com',
            'role' => 'cashier', 'password' => 'Initial-secret-782!'])->assertRedirect()->assertSessionHasNoErrors();
        $staff = User::where('email', 'audited.cashier@example.com')->firstOrFail();
        $initialHash = $staff->password;
        $staff->forceFill(['remember_token' => 'private-remember-token-782'])->save();
        $this->post(route('admin.users.reset-password', $staff), ['password' => 'Replacement-secret-783!'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $replacementHash = $staff->fresh()->password;
        $this->delete(route('admin.users.destroy', $staff))->assertRedirect()->assertSessionHasNoErrors();

        $events = DB::table('audit_logs')->where('auditable_type', User::class)->where('auditable_id', $staff->id)->orderBy('id')->get();
        $this->assertSame(['user.created', 'user.password_reset', 'user.deactivated'], $events->pluck('event')->all());
        $this->assertSame([$owner->id], $events->pluck('user_id')->unique()->all());
        $this->assertFalse($staff->fresh()->is_active);
        foreach ($events as $event) {
            $metadata = json_decode($event->new_values, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('audited.cashier@example.com', $metadata['email']);
            $this->assertSame(['cashier'], $metadata['roles']);
            foreach (['Initial-secret-782!', 'Replacement-secret-783!', 'private-remember-token-782', $initialHash, $replacementHash] as $secret) {
                $this->assertStringNotContainsString($secret, $event->new_values);
            }
            $this->assertArrayNotHasKey('password', $metadata);
            $this->assertArrayNotHasKey('remember_token', $metadata);
        }
    }

    public function test_account_creation_rolls_back_when_security_audit_cannot_be_recorded(): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $rolesBefore = DB::table('model_has_roles')->count();
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated audit failure.'));

        $this->actingAs($owner)->post(route('admin.users.store'), ['name' => 'Incomplete cashier', 'email' => 'incomplete.cashier@example.com',
            'role' => 'cashier', 'password' => 'Rollback-secret-782!'])->assertInternalServerError();

        $this->assertDatabaseMissing('users', ['email' => 'incomplete.cashier@example.com']);
        $this->assertSame($rolesBefore, DB::table('model_has_roles')->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.created']);
    }

    public function test_password_reset_keeps_original_credentials_tokens_and_sessions_if_audit_fails(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $target = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $target->forceFill(['remember_token' => 'retained-token-782'])->save();
        $originalHash = $target->password;
        DB::table('sessions')->insert(['id' => 'retained-session-782', 'user_id' => $target->id, 'payload' => base64_encode('{}'), 'last_activity' => now()->timestamp]);
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated audit failure.'));

        $this->actingAs($owner)->post(route('admin.users.reset-password', $target), ['password' => 'Rollback-secret-783!'])->assertInternalServerError();

        $this->assertSame($originalHash, $target->fresh()->password);
        $this->assertSame('retained-token-782', $target->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-session-782', 'user_id' => $target->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.password_reset']);
    }

    public function test_only_handover_participants_and_property_reviewers_see_a_designated_drawer_count(): void
    {
        config(['sedona.continuous_shift_enabled' => false, 'sedona.shift_handover_enabled' => true]);
        $outgoing = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $incoming = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $unrelated = User::where('email', 'ann@sedonapms.com')->firstOrFail();
        $shift = Shift::create(['opened_by' => $outgoing->id, 'closed_by' => $outgoing->id, 'incoming_user_id' => $incoming->id,
            'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()->subHour(), 'closed_at' => now(),
            'handover_status' => 'pending', 'cash_total' => 12345.67]);

        $this->actingAs($unrelated)->get(route('shifts.index'))->assertOk()
            ->assertDontSee('Outgoing count ₱12345.67')->assertDontSee('Incoming drawer handover #'.$shift->id);
        $this->actingAs($incoming)->get(route('shifts.index'))->assertOk()->assertSee('Outgoing count ₱12345.67');
        $this->actingAs($outgoing)->get(route('shifts.index'))->assertOk()->assertSee('Outgoing count ₱12345.67');
        $this->actingAs(User::where('email', 'admin1@sedonapms.com')->firstOrFail())->get(route('shifts.index'))
            ->assertOk()->assertSee('Outgoing count ₱12345.67');
    }

    public function test_unassigned_handover_remains_available_to_a_different_incoming_operator(): void
    {
        config(['sedona.continuous_shift_enabled' => false, 'sedona.shift_handover_enabled' => true]);
        $outgoing = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        Shift::create(['opened_by' => $outgoing->id, 'closed_by' => $outgoing->id,
            'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now()->subHour(), 'closed_at' => now(),
            'handover_status' => 'pending', 'cash_total' => 125]);

        $this->actingAs(User::where('email', 'raquel@sedonapms.com')->firstOrFail())->get(route('shifts.index'))
            ->assertOk()->assertSee('Outgoing count ₱125.00')->assertSee('Count and accept handover');
    }

    /** @return array<string, array{string, string, int, float}> */
    public static function custodyMovements(): array
    {
        return [
            'drop to safe' => ['cash_drop', 'safe', -20, 80.0],
            'owner replenishment' => ['float_addition', 'owner_funds', 30, 130.0],
            'deposit to bank' => ['bank_deposit', 'bank', -35, 65.0],
        ];
    }

    #[DataProvider('custodyMovements')]
    public function test_remittance_shows_signed_custody_movements_in_the_expected_cash_reconciliation(string $kind, string $destination, int $amount, float $expected): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $shift = Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => today(),
            'opened_at' => now()->subHour(), 'opening_float' => 100]);
        CashMovement::factory()->create(['shift_id' => $shift->id, 'user_id' => $cashier->id,
            'kind' => $kind, 'destination' => $destination, 'amount' => $amount, 'reference' => 'CUSTODY-SLIP-672']);

        $this->actingAs($cashier)->get(route('shifts.remittance_slip', $shift))->assertOk()
            ->assertViewHas('expectedCash', $expected)->assertViewHas('cashMovementTotal', (float) $amount)
            ->assertSee('NET TRANSFERS / REPLENISHMENT:')->assertSee('CUSTODY-SLIP-672')
            ->assertSee(strtoupper(str_replace('_', ' ', $kind)))->assertSee(number_format($expected, 2));
    }

    public function test_closed_remittance_retains_saved_count_expected_cash_and_variance_with_custody_details(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $shift = Shift::create(['opened_by' => $cashier->id, 'closed_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => today(),
            'opened_at' => now()->subHour(), 'closed_at' => now(), 'opening_float' => 100,
            'cash_total' => 77, 'expected_cash' => 80, 'cash_variance' => -3]);
        CashMovement::factory()->create(['shift_id' => $shift->id, 'user_id' => $cashier->id,
            'amount' => -20, 'reference' => 'CLOSED-CUSTODY-673']);

        $this->actingAs($cashier)->get(route('shifts.remittance_slip', $shift))->assertOk()
            ->assertViewHas('expectedCash', 80.0)->assertViewHas('actualCash', 77.0)->assertViewHas('variance', -3.0)
            ->assertViewHas('cashMovementTotal', -20.0)->assertSee('CLOSED-CUSTODY-673')->assertSee('SHORTAGE');
    }

    /** @return array<string, array{string, string}> */
    public static function spreadsheetText(): array
    {
        return [
            'equals formula' => ['=SUM(1,1)', "'=SUM(1,1)"],
            'plus formula' => ['+1+1', "'+1+1"],
            'minus formula' => ['-1+1', "'-1+1"],
            'at formula' => ['@SUM(1,1)', "'@SUM(1,1)"],
            'leading spaces' => ['  =1+1', "'  =1+1"],
            'tab prefix' => ["\t=1+1", "'\t=1+1"],
            'ordinary text' => ['Count confirmed, no damage', 'Count confirmed, no damage'],
        ];
    }

    #[DataProvider('spreadsheetText')]
    public function test_inventory_export_treats_staff_text_as_data_and_preserves_numeric_movements(string $text, string $escaped): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $item = PosItem::create(['name' => $text, 'category' => $text, 'price' => 20, 'stock_quantity' => 9,
            'is_tracked' => true, 'is_available' => true]);
        InventoryEvent::create(['pos_item_id' => $item->id, 'item_name' => $text, 'event_type' => 'adjustment',
            'quantity_change' => -1, 'balance_after' => 9, 'reference_id' => $text, 'user_id' => $owner->id,
            'operator_name' => $text, 'notes' => $text]);

        $response = $this->actingAs($owner)->get(route('inventory.export-csv'))->assertOk();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $response->getContent());
        rewind($handle);
        fgetcsv($handle);
        $row = fgetcsv($handle);
        fclose($handle);

        foreach ([2, 3, 7, 8, 9] as $index) {
            $this->assertSame($escaped, $row[$index]);
        }
        $this->assertSame('-1', $row[5]);
        $this->assertSame('9', $row[6]);
    }
}
