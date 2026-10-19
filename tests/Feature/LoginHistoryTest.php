<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-10 14:03:21');
    }

    /** @return array<string, array{string}> */
    public static function staffRoles(): array
    {
        return [
            'cashier' => ['cashier'], 'kitchen' => ['kitchen'], 'display' => ['customer_display'],
            'front desk' => ['front_desk'], 'manager' => ['manager'], 'admin' => ['admin'], 'owner' => ['owner'],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_successful_staff_login_records_exact_time_and_safe_account_snapshot(string $role): void
    {
        $staff = $this->staff($role);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.0.42'])->withHeader('User-Agent', 'Sedona Test Browser')
            ->post(route('login.submit'), ['email' => $staff->email, 'password' => 'password', 'remember' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($staff);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $staff->id,
            'created_at' => '2026-10-10 14:03:21', 'ip_address' => '192.168.0.42', 'user_agent' => 'Sedona Test Browser']);
        $event = DB::table('audit_logs')->where('event', 'auth.login')->sole();
        $details = json_decode($event->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['name' => $staff->name, 'email' => $staff->email, 'roles' => [$role], 'remember' => true], $details);
        $this->assertStringNotContainsString($staff->password, $event->new_values);
        $this->assertStringNotContainsString($staff->fresh()->remember_token, $event->new_values);
        $this->assertArrayNotHasKey('password', $details);
    }

    public function test_sign_out_records_its_own_exact_time_without_duplicating_sign_in_on_page_requests(): void
    {
        $owner = $this->staff('owner');
        $this->post(route('login.submit'), ['email' => $owner->email, 'password' => 'password']);
        $this->get(route('admin.login-history.index'))->assertOk();
        $this->get(route('admin.login-history.index'))->assertOk();
        $this->travelTo('2026-10-10 16:42:09');

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $owner->id, 'created_at' => '2026-10-10 14:03:21']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'user_id' => $owner->id, 'created_at' => '2026-10-10 16:42:09']);
    }

    public function test_failed_sign_in_records_an_attempt_without_storing_submitted_secrets(): void
    {
        $this->post(route('login.submit'), ['email' => 'unknown@example.com', 'password' => 'Do-not-store-this-credential',
            'remember_token' => 'do-not-store-this-token'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.failed', 'user_id' => null, 'created_at' => '2026-10-10 14:03:21']);
        $details = DB::table('audit_logs')->sole()->new_values;
        $this->assertStringContainsString('unknown@example.com', $details);
        $this->assertStringNotContainsString('Do-not-store-this-credential', $details);
        $this->assertStringNotContainsString('do-not-store-this-token', $details);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.login']);
    }

    public function test_disabled_account_is_recorded_as_failed_sign_in_and_never_as_successful(): void
    {
        $cashier = $this->staff('cashier');
        $cashier->update(['is_active' => false]);

        $this->post(route('login.submit'), ['email' => $cashier->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.failed']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.login']);
    }

    public function test_remembered_sign_in_is_recorded_when_a_new_session_is_restored(): void
    {
        $owner = $this->staff('owner');
        $this->post(route('login.submit'), ['email' => $owner->email, 'password' => 'password', 'remember' => true]);
        $cookieName = auth()->guard()->getRecallerName();
        $cookieValue = $owner->id.'|'.$owner->fresh()->remember_token.'|'.$owner->password;
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        $this->travelTo('2026-10-11 07:08:09');

        $this->withCookie($cookieName, $cookieValue)->get(route('admin.login-history.index'))->assertOk();

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $owner->id, 'created_at' => '2026-10-11 07:08:09']);
    }

    /** @return array<string, array{string}> */
    public static function reviewers(): array
    {
        return ['admin' => ['admin'], 'owner' => ['owner']];
    }

    #[DataProvider('reviewers')]
    public function test_admin_and_owner_can_view_history_and_navigation_link(string $role): void
    {
        $reviewer = $this->staff($role);
        $this->recordEvent('auth.login', 'Audited Cashier', 'audited@example.com');

        $this->actingAs($reviewer)->get(route('admin.login-history.index'))->assertOk()
            ->assertSee('Login History')->assertSee('Audited Cashier')->assertSee('audited@example.com')
            ->assertSee('02:03:21 PM')->assertSee('Asia/Manila')->assertSee('192.168.0.42');
    }

    /** @return array<string, array{string}> */
    public static function restrictedRoles(): array
    {
        return ['cashier' => ['cashier'], 'kitchen' => ['kitchen'], 'display' => ['customer_display'],
            'front desk' => ['front_desk'], 'manager' => ['manager']];
    }

    #[DataProvider('restrictedRoles')]
    public function test_other_roles_cannot_view_login_history(string $role): void
    {
        $staff = $this->staff($role);

        $this->actingAs($staff)->get(route('admin.login-history.index'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.login-history.index'))->assertRedirect(route('login'));
    }

    public function test_filters_use_account_ip_event_and_inclusive_calendar_dates_with_stable_pagination(): void
    {
        $owner = $this->staff('owner');
        $this->recordEvent('auth.logout', 'Match Cashier', 'match@example.com', '2026-10-10 23:59:59');
        $this->recordEvent('auth.logout', 'Other Cashier', 'other@example.com', '2026-10-10 23:59:59', '192.168.0.43');
        $this->recordEvent('auth.login', 'Match Cashier', 'match@example.com', '2026-10-10 10:00:00');
        $this->recordEvent('auth.logout', 'Match Cashier', 'match@example.com', '2026-10-11 00:00:00');
        $this->recordEvent('folio.checkout', 'Match Cashier', 'match@example.com');

        $this->actingAs($owner)->get(route('admin.login-history.index', ['q' => 'match@example.com', 'event' => 'auth.logout',
            'from' => '2026-10-10', 'to' => '2026-10-10']))->assertOk()
            ->assertViewHas('events', fn ($events): bool => $events->total() === 1 && $events->first()->details['email'] === 'match@example.com');
        $this->get(route('admin.login-history.index', ['q' => '192.168.0.43']))->assertViewHas('events', fn ($events): bool => $events->total() === 1);
        $this->get(route('admin.login-history.index', ['q' => 'Other Cashier']))->assertViewHas('events', fn ($events): bool => $events->total() === 1);

        for ($index = 0; $index < 31; $index++) {
            $this->recordEvent('auth.login', 'Page '.$index, 'paging@example.com');
        }
        $this->get(route('admin.login-history.index', ['q' => 'paging@example.com']))->assertViewHas('events',
            fn ($events): bool => $events->total() === 31 && $events->count() === 30 && $events->first()->details['name'] === 'Page 30')
            ->assertSee('q=paging%40example.com', false);
        $this->get(route('admin.login-history.index', ['q' => 'paging@example.com', 'page' => 2]))->assertViewHas('events',
            fn ($events): bool => $events->count() === 1 && $events->first()->details['name'] === 'Page 0');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidFilters(): array
    {
        return ['bad date' => [['from' => 'not-a-date'], 'from'],
            'backward dates' => [['from' => '2026-10-10', 'to' => '2026-10-09'], 'to'],
            'unknown event' => [['event' => 'folio.checkout'], 'event'],
            'long search' => [['q' => str_repeat('a', 101)], 'q']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_history_filters_return_validation_errors(array $filters, string $field): void
    {
        $owner = $this->staff('owner');

        $this->actingAs($owner)->get(route('admin.login-history.index', $filters))->assertSessionHasErrors($field);
    }

    public function test_saved_account_snapshot_survives_account_changes_and_escapes_browser_details(): void
    {
        $owner = $this->staff('owner');
        $cashier = $this->staff('cashier');
        $this->post(route('login.submit'), ['email' => $cashier->email, 'password' => 'password']);
        $oldName = $cashier->name;
        $cashier->update(['name' => 'Renamed Staff', 'is_active' => false]);
        DB::table('audit_logs')->update(['user_agent' => '<script>alert("browser")</script>']);

        $this->actingAs($owner)->get(route('admin.login-history.index'))->assertOk()
            ->assertSee($oldName)->assertDontSee('Renamed Staff')->assertSee('&lt;script&gt;alert(', false)
            ->assertDontSee('<script>alert("browser")</script>', false);
    }

    public function test_cashier_audit_page_does_not_expose_even_its_own_login_events(): void
    {
        $cashier = $this->staff('cashier');
        $cashier->givePermissionTo(Permission::findOrCreate('record_payments', 'web'));
        $this->post(route('login.submit'), ['email' => $cashier->email, 'password' => 'password']);

        $this->get(route('transactions.audit'))->assertOk()->assertViewHas('events', fn ($events): bool => $events->total() === 0);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    private function recordEvent(string $event, string $name, string $email, string $time = '2026-10-10 14:03:21', string $ip = '192.168.0.42'): void
    {
        DB::table('audit_logs')->insert(['event' => $event, 'new_values' => json_encode(['name' => $name, 'email' => $email,
            'roles' => ['cashier']], JSON_THROW_ON_ERROR), 'created_at' => $time, 'ip_address' => $ip, 'user_agent' => 'Test Browser']);
    }
}
