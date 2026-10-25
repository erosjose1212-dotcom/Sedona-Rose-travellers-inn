<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecentChangesQcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-25 19:00:00');
    }

    public function test_print_retry_preserves_preparation_time_and_does_not_open_a_second_dialog(): void
    {
        [$actor, $folio] = $this->stay();
        $key = ['operation_key' => (string) Str::uuid()];
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), $key)->assertOk();
        $this->travel(1)->hours();

        $response = $this->post(route('folios.billing.print', $folio), $key)->assertOk();

        $this->assertMatchesRegularExpression('~Bill Prepared:</span>\s*<span>10/25/2026 07:00 PM</span>~', $response->getContent());
        $response->assertViewHas('printRequested', false);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'billing.print_requested')->count());
    }

    public function test_legacy_retry_without_a_snapshot_requires_a_new_tracked_print(): void
    {
        [$actor, $folio] = $this->stay();
        $key = ['operation_key' => (string) Str::uuid()];
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), $key)->assertOk();
        DB::table('financial_operations')->where('operation_key', $key['operation_key'])->update(['result' => json_encode(['copy_number' => 1])]);

        $this->post(route('folios.billing.print', $folio), $key)->assertRedirect(route('folios.billing', $folio))->assertSessionHasErrors('print');

        $this->assertSame(1, DB::table('audit_logs')->where('event', 'billing.print_requested')->count());
    }

    public function test_print_authorization_is_revoked_after_the_printer_dialog_closes(): void
    {
        [$actor, $folio] = $this->stay();
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), ['operation_key' => (string) Str::uuid()])->assertOk()
            ->assertSee("window.addEventListener('afterprint'", false)
            ->assertSee("classList.remove('billing-print-authorized')", false);
        if ($directory = getenv('SEDONA_QC_EXPORT_DIR')) {
            $response = $this->post(route('folios.billing.print', $folio), ['operation_key' => (string) Str::uuid()])->assertOk();
            file_put_contents($directory.'/billing-authorized.html', $response->getContent());
        }
    }

    public function test_audit_summary_uses_hotel_time_when_the_server_stores_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->beforeApplicationDestroyed(fn () => date_default_timezone_set($originalTimezone));
        [$actor, $folio] = $this->stay('admin');
        $this->travelTo(CarbonImmutable::parse('2026-10-25T19:00:00+08:00')->setTimezone('UTC'));
        $this->actingAs($actor)->post(route('folios.billing.print', $folio), ['operation_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('2026-10-25 11:00:00', DB::table('audit_logs')->where('event', 'billing.print_requested')->value('created_at'));

        $this->get(route('transactions.audit'))->assertOk()->assertSee('10/25/2026 07:00 PM')->assertDontSee('2026-10-25 11:00:00');
    }

    #[TestWith([100])]
    #[TestWith([65535])]
    public function test_valid_large_order_quantities_retain_all_amounts(int $quantity): void
    {
        [$actor, $folio] = $this->stay();
        $folio->update(['pos_items' => [['name' => 'Sizzling sisig with egg and vegetables extra large portion', 'qty' => $quantity, 'price' => 230, 'subtotal' => $quantity * 230]]]);
        $response = $this->actingAs($actor)->get(route('folios.orderslip', $folio))->assertOk()
            ->assertSee((string) $quantity)->assertSee(number_format($quantity * 230, 2))->assertSee('class="large-order-line"', false);
        if ($directory = getenv('SEDONA_QC_EXPORT_DIR')) {
            file_put_contents($directory.'/order-stress-'.$quantity.'.html', $response->getContent());
        }
    }

    #[TestWith([12])]
    #[TestWith([24])]
    public function test_a_target_package_that_has_exactly_elapsed_cannot_be_selected(int $hours): void
    {
        [$actor, $folio] = $this->stay();
        $folio->update(['stay_mode' => 'open', 'checked_in_at' => now()->subHours($hours), 'expected_checkout_at' => now()->subHours($hours - 3)]);
        $this->actingAs($actor)->post(route('folios.convert', $folio), ['operation_key' => (string) Str::uuid(), 'rate_tier' => $hours.'h', 'reason' => 'Expired target'])->assertSessionHasErrors('rate_tier');
        $this->assertSame('open', $folio->fresh()->stay_mode);
    }

    public function test_open_time_with_no_forward_package_keeps_checkout_available(): void
    {
        [$actor, $folio] = $this->stay();
        $folio->update(['stay_mode' => 'open', 'checked_in_at' => now()->subHours(26), 'expected_checkout_at' => now()->subHours(23)]);
        $response = $this->actingAs($actor)->get(route('dashboard'))->assertOk()
            ->assertViewHas('folioExtensionOptions', fn (array $options): bool => ! $options[$folio->id]['canConvert'] && $options[$folio->id]['conversions'] === []);
        $this->get(route('checkout.process', $folio))->assertOk()->assertSee('Save check-in time');
        if ($directory = getenv('SEDONA_QC_EXPORT_DIR')) {
            $html = str_replace('</head>', '<link rel="stylesheet" href="/court-app.css"></head>', $response->getContent());
            file_put_contents($directory.'/no-forward-package.html', $html);
        }
    }

    #[TestWith(['2026-10-25 20:00:00', true])]
    #[TestWith(['2026-10-25 19:59:59', false])]
    public function test_conversion_respects_the_exact_future_reservation_boundary(string $arrival, bool $allowed): void
    {
        [$actor, $folio] = $this->stay();
        $folio->update(['checked_in_at' => '2026-10-25 08:00:00', 'expected_checkout_at' => '2026-10-25 11:00:00']);
        Booking::create(['room_id' => $folio->room_id, 'user_id' => $actor->id, 'guest_name' => 'Future guest', 'guest_contact' => 'QC', 'headcount' => 2,
            'rate_tier' => '3h', 'arrival_at' => $arrival, 'departure_at' => CarbonImmutable::parse($arrival)->addHours(3), 'status' => 'scheduled']);
        $response = $this->actingAs($actor)->post(route('folios.convert', $folio), ['operation_key' => (string) Str::uuid(), 'rate_tier' => '12h', 'reason' => 'Twelve-hour package requested']);
        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertSame('12h', $folio->fresh()->rate_tier);
        } else {
            $response->assertSessionHasErrors('room_id');
            $this->assertSame('3h', $folio->fresh()->rate_tier);
        }
    }

    private function stay(string $role = 'cashier'): array
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'view_dashboard', 'record_payments', 'post_pos_sales', 'view_all_shifts'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $room = Room::create(['number' => '40', 'floor' => 1, 'name' => 'Test room', 'type' => 'Classic Room', 'status' => 'occupied', 'base_rate_3h' => 395, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100]);
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Private QC Guest', 'headcount' => 2])->id, 'user_id' => $actor->id,
            'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'room_charge' => 395, 'status' => 'active', 'checked_in_at' => now()->subHours(2), 'expected_checkout_at' => now()->addHour()]);

        return [$actor, $folio];
    }
}
