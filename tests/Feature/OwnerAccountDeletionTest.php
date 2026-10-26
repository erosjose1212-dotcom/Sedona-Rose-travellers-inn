<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentEntry;
use App\Models\Room;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class OwnerAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_owner_removes_active_or_deactivated_accounts_without_losing_financial_attribution(bool $active): void
    {
        $staff = User::factory()->create(['email' => 'removed-cashier@example.com', 'is_active' => $active, 'remember_token' => 'old-remember-token'])->assignRole('cashier');
        $folio = Folio::create(['room_id' => Room::where('type', 'Classic Room')->where('is_staff_quarters', false)->firstOrFail()->id,
            'guest_id' => Guest::create(['name' => 'Deletion history guest', 'headcount' => 1])->id,
            'user_id' => $staff->id, 'checked_out_by' => $staff->id, 'transaction_id' => 'STAY-DELETE-TEST',
            'status' => 'checked_out', 'checked_in_at' => now()->subHours(3), 'expected_checkout_at' => now(), 'checked_out_at' => now(),
            'rate_tier' => '3h', 'room_charge' => 395, 'gross_total' => 395, 'net_total' => 395, 'payment_method' => 'cash', 'cash_tendered' => 395]);
        $payment = PaymentEntry::create(['folio_id' => $folio->id, 'user_id' => $staff->id, 'kind' => 'settlement',
            'cash_amount' => 395, 'gcash_amount' => 0, 'posted_at' => now(), 'business_date' => today()->toDateString()]);
        $folioBefore = $folio->refresh()->getAttributes();
        $paymentBefore = $payment->refresh()->getAttributes();

        $this->actingAs($this->owner())->delete(route('admin.users.destroy', $staff))->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('admin.users.index'))->assertViewHas('users', fn ($users): bool => ! $users->contains('id', $staff->id));
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertNotNull($staff->fresh()->removed_at);
        $this->assertNull($staff->fresh()->remember_token);
        $this->assertSame($folioBefore, $folio->fresh()->getAttributes());
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertSame($staff->name, $folio->fresh()->cashier->name);
        $this->assertSame($staff->name, $payment->fresh()->user->name);
        $this->assertSame(395.0, (float) PaymentEntry::sum('cash_amount'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->owner()->id, 'event' => 'user.deleted', 'auditable_id' => $staff->id]);
    }

    #[TestWith(['admin'])]
    #[TestWith(['cashier'])]
    public function test_non_owners_cannot_delete_staff_accounts(string $role): void
    {
        $actor = User::role($role)->firstOrFail();
        $staff = User::factory()->create()->assignRole('cashier');

        $this->actingAs($actor)->delete(route('admin.users.destroy', $staff))->assertForbidden();

        $this->assertTrue($staff->fresh()->is_active);
        $this->assertNull($staff->fresh()->removed_at);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.deleted', 'auditable_id' => $staff->id]);
    }

    public function test_owner_cannot_remove_their_own_account(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->delete(route('admin.users.destroy', $owner))->assertRedirect()->assertSessionHas('error');

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertNull($owner->fresh()->removed_at);
    }

    public function test_removed_account_sessions_are_revoked_and_login_is_blocked(): void
    {
        config(['session.driver' => 'database']);
        $staff = User::factory()->create(['password' => 'Test-password-832!'])->assignRole('cashier');
        DB::table('sessions')->insert(['id' => 'removed-account-session', 'user_id' => $staff->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);

        $this->actingAs($this->owner())->delete(route('admin.users.destroy', $staff))->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'removed-account-session']);
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $staff->email, 'password' => 'Test-password-832!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_repeated_removal_has_one_audit_event_and_cannot_reset_deleted_credentials(): void
    {
        $staff = User::factory()->create()->assignRole('cashier');
        $beforePassword = $staff->password;
        $this->actingAs($this->owner())->delete(route('admin.users.destroy', $staff))->assertRedirect();
        $firstRemoval = $staff->fresh()->removed_at;

        $this->delete(route('admin.users.destroy', $staff))->assertRedirect();
        $this->post(route('admin.users.reset-password', $staff), ['password' => 'Changed-password-832!'])->assertNotFound();

        $this->assertSame($firstRemoval->toIso8601String(), $staff->fresh()->removed_at->toIso8601String());
        $this->assertSame($beforePassword, $staff->fresh()->password);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'user.deleted')->where('auditable_id', $staff->id)->count());
    }

    public function test_failed_audit_rolls_back_removal_and_session_revocation(): void
    {
        config(['session.driver' => 'database']);
        $staff = User::factory()->create(['remember_token' => 'retained-token'])->assignRole('cashier');
        DB::table('sessions')->insert(['id' => 'retained-account-session', 'user_id' => $staff->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated audit failure'));

        $this->actingAs($this->owner())->delete(route('admin.users.destroy', $staff))->assertServerError();

        $this->assertTrue($staff->fresh()->is_active);
        $this->assertNull($staff->fresh()->removed_at);
        $this->assertSame('retained-token', $staff->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-account-session']);
    }

    private function owner(): User
    {
        return User::role('owner')->firstOrFail();

    }
}
