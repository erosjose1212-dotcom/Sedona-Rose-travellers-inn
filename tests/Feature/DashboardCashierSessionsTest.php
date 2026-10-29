<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardCashierSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-27 10:00:00');
        config(['session.driver' => 'database', 'session.lifetime' => 120, 'session.lottery' => [0, 100]]);
    }

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_property_board_shows_current_cashiers_once_with_latest_activity_even_for_a_past_report(string $role): void
    {
        $viewer = $this->staff($role, 'Property viewer');
        $cashier = $this->staff('cashier', 'Alice Cashier');
        $frontDesk = $this->staff('front_desk', 'Ben Front Desk');
        $this->sessionFor($cashier, 'device-one', now()->subMinutes(20)->timestamp);
        $this->sessionFor($cashier, 'device-two', now()->subMinutes(2)->timestamp);
        $this->sessionFor($frontDesk, 'front-desk', now()->subMinutes(120)->timestamp);

        $response = $this->actingAs($viewer)->get(route('dashboard', ['shift_date' => '2026-10-20']))
            ->assertSee('Cashiers signed in now')->assertSee('Alice Cashier')->assertSee('Ben Front Desk');

        $cashiers = $response->viewData('signedInCashiers');
        $this->assertSame(['Alice Cashier', 'Ben Front Desk'], $cashiers->pluck('name')->all());
        $this->assertSame(now()->subMinutes(2)->timestamp, $cashiers->first()['last_active_at']->timestamp);
        $this->assertSame('Asia/Manila', $cashiers->first()['last_active_at']->timezoneName);
        $response->assertDontSee('SECRET-SESSION-PAYLOAD');
    }

    public function test_expired_disabled_removed_and_non_cashier_accounts_are_excluded(): void
    {
        $viewer = $this->staff('admin', 'Property viewer');
        $expired = $this->staff('cashier', 'Expired Cashier');
        $disabled = $this->staff('cashier', 'Disabled Cashier');
        $removed = $this->staff('cashier', 'Removed Cashier');
        $kitchen = $this->staff('kitchen', 'Kitchen Operator');
        $disabled->update(['is_active' => false]);
        $removed->forceFill(['removed_at' => now()])->save();
        $this->sessionFor($expired, 'expired', now()->subMinutes(120)->subSecond()->timestamp);
        foreach ([$disabled, $removed, $kitchen, $viewer] as $staff) {
            $this->sessionFor($staff, 'staff-'.$staff->id, now()->timestamp);
        }

        $response = $this->actingAs($viewer)->get(route('dashboard'))
            ->assertSee('No cashier signed in.');

        $this->assertCount(0, $response->viewData('signedInCashiers'));
        $response->assertDontSee(['Expired Cashier', 'Disabled Cashier', 'Removed Cashier', 'Kitchen Operator']);
    }

    #[TestWith(['cashier'])]
    #[TestWith(['front_desk'])]
    #[TestWith(['manager'])]
    public function test_other_roles_do_not_receive_the_signed_in_cashier_list(string $role): void
    {
        $viewer = $this->staff($role, 'Other viewer');
        $cashier = $this->staff('cashier', 'Private Cashier Identity');
        $this->sessionFor($cashier, 'private-cashier', now()->timestamp);

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertViewHas('signedInCashiers', fn ($cashiers): bool => $cashiers === null)
            ->assertDontSee(['Cashiers signed in now', 'Private Cashier Identity']);
    }

    public function test_logout_removes_the_cashier_from_the_board(): void
    {
        $cashier = $this->staff('cashier', 'Signing Out Cashier');
        $owner = $this->staff('owner', 'Property viewer');
        $this->post(route('login.submit'), ['email' => $cashier->email, 'password' => 'password'])->assertRedirect();
        $sessionId = session()->getId();
        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $cashier->id]);

        $this->withCookie(config('session.cookie'), $sessionId)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $response = $this->actingAs($owner)->get(route('dashboard'))->assertSee('No cashier signed in.');
        $this->assertCount(0, $response->viewData('signedInCashiers'));
    }

    public function test_non_database_session_storage_reports_unavailable_instead_of_false_presence(): void
    {
        config(['session.driver' => 'array']);
        $viewer = $this->staff('owner', 'Property viewer');
        $this->sessionFor($this->staff('cashier', 'Old Session Cashier'), 'old-driver', now()->timestamp);

        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertViewHas('signedInCashiers', fn ($cashiers): bool => $cashiers === null)
            ->assertSee('Cashier session status unavailable.')->assertDontSee('Old Session Cashier');
    }

    private function staff(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(Role::findOrCreate($role, 'web'));
        $user->givePermissionTo(Permission::findOrCreate('view_dashboard', 'web'));

        return $user;
    }

    private function sessionFor(User $user, string $id, int $lastActivity): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id,
            'payload' => base64_encode('SECRET-SESSION-PAYLOAD'), 'last_activity' => $lastActivity,
        ]);
    }
}
