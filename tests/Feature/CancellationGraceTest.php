<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CancellationGraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-13 12:00:00');
    }

    public function test_admin_cancels_unpaid_stay_once_and_keeps_its_stay_reference(): void
    {
        $actor = $this->actor('admin');
        $folio = $this->folio($actor);
        $booking = Booking::create(['room_id' => $folio->room_id, 'user_id' => $actor->id, 'guest_name' => 'Cancelled reservation', 'headcount' => 1,
            'rate_tier' => '3h', 'arrival_at' => now(), 'departure_at' => now()->addHours(3), 'status' => 'checked_in']);
        $folio->update(['booking_id' => $booking->id]);
        $payload = $this->key() + ['reason' => 'Guest changed their mind.'];

        $this->actingAs($actor)->get(route('folios.orders.index', $folio))->assertSee('Reason for cancelling stay');
        $this->actingAs($actor)->post('/folios/'.$folio->id.'/cancel', $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post('/folios/'.$folio->id.'/cancel', $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'status' => 'voided', 'transaction_id' => 'STAY-20261013-001', 'net_total' => 0, 'gross_total' => 0]);
        $this->assertSame('available', $folio->room->fresh()->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->get(route('admin.accounting.ledger'))
            ->assertDontSee(route('folios.receipt', $folio), false)
            ->assertSee(route('folios.billing', $folio), false);
        $this->assertSame('STAY-20261013-002', Folio::generateTransactionId());
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'folio.cancelled')->count());
        $audit = json_decode(DB::table('audit_logs')->where('event', 'folio.cancelled')->value('new_values'), true);
        $this->assertSame('395.00', $audit['before']['room_charge']);
        $this->assertSame($payload['reason'], $audit['reason']);
    }

    #[TestWith([900, true])]
    #[TestWith([901, false])]
    public function test_stay_cancellation_window_is_fifteen_minutes_from_creation(int $elapsed, bool $allowed): void
    {
        $actor = $this->actor('admin');
        $folio = $this->folio($actor);
        $folio->update(['checked_in_at' => now()->subHours(2)]);
        $this->travel($elapsed)->seconds();
        $response = $this->actingAs($actor)->post('/folios/'.$folio->id.'/cancel', $this->key() + ['reason' => 'Cancel new stay']);
        if ($allowed) {
            $response->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame('voided', $folio->fresh()->status);
        } else {
            $response->assertSessionHasErrors('cancel');
            $this->assertSame('active', $folio->fresh()->status);
            $this->assertSame('occupied', $folio->room->fresh()->status);
        }
    }

    public function test_money_received_settled_stays_and_staff_quarters_cannot_be_cancelled(): void
    {
        $actor = $this->actor('admin');
        $folio = $this->folio($actor);
        $url = '/folios/'.$folio->id.'/cancel';
        $folio->update(['security_deposit' => 500]);
        $this->actingAs($actor)->post($url, $this->key() + ['reason' => 'Cancel'])->assertSessionHasErrors('cancel');
        $folio->update(['security_deposit' => 0, 'status' => 'checked_out']);
        $this->post($url, $this->key() + ['reason' => 'Cancel'])->assertSessionHasErrors('cancel');
        $folio->update(['status' => 'active']);
        $folio->room->update(['is_staff_quarters' => true]);
        $this->post($url, $this->key() + ['reason' => 'Cancel'])->assertSessionHasErrors('cancel');
        $this->assertSame('active', $folio->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'folio.cancelled')->count());
    }

    #[TestWith(['cashier'])]
    #[TestWith(['owner'])]
    public function test_non_admin_cannot_cancel_stay_even_with_folio_permission(string $role): void
    {
        $actor = $this->actor($role);
        $folio = $this->folio($actor);

        $this->actingAs($actor)->get(route('folios.orders.index', $folio))->assertDontSee('cancelGuestStay')->assertDontSee('Reason for cancelling stay');
        $this->post('/folios/'.$folio->id.'/cancel', $this->key() + ['reason' => 'Cancel stay'])->assertForbidden();

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'status' => 'active', 'room_charge' => 395, 'transaction_id' => 'STAY-20261013-001']);
        $this->assertSame('occupied', $folio->room->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'folio.cancelled')->count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cashier_cancels_unprepared_order_once_restoring_stock_and_ingredients(bool $posScreen): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $egg = $this->item('Egg (Fried/Boiled)', 'Kitchen Extras', false);
        $dish = $this->item('Tapsilog', 'Breakfast', true);
        $water = $this->item('Water', 'Drinks', false);
        $this->actingAs($actor);
        if ($posScreen) {
            $this->post(route('pos.order.store'), $this->key() + ['order_type' => 'room_charge', 'room_id' => $folio->room_id, 'folio_id' => $folio->id,
                'items' => [['id' => $dish->id, 'qty' => 2], ['id' => $water->id, 'qty' => 1]]])->assertSessionHasNoErrors();
        } else {
            $this->post(route('folios.addon', $folio), $this->key() + ['items' => [['id' => $dish->id, 'qty' => 2], ['id' => $water->id, 'qty' => 1]]])->assertSessionHasNoErrors();
        }
        $order = Order::firstOrFail();
        $this->get(route('folios.orders.index', $folio))->assertSee('Cancel order')->assertDontSee('Reason for cancelling order')->assertDontSee('cancelGuestStay');
        $this->post('/folios/'.$folio->id.'/cancel', $this->key() + ['reason' => 'Cancel stay'])->assertForbidden();
        $this->travel(15)->minutes();
        $payload = $this->key() + ['panel' => 1];
        $url = '/folios/'.$folio->id.'/orders/'.$order->id.'/cancel';

        $this->post($url, $payload)->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('folios.orders.index', ['folio' => $folio, 'panel' => 1]));
        $this->post($url, $payload)->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->total);
        $this->assertSame(0, (int) $order->items()->sum('quantity'));
        $this->assertSame(10, $dish->fresh()->stock_quantity);
        $this->assertSame(10, $egg->fresh()->stock_quantity);
        $this->assertSame(10, $water->fresh()->stock_quantity);
        $this->assertSame('0.00', $folio->fresh()->pos_total);
        $this->assertSame('active', $folio->fresh()->status);
        $this->assertSame('occupied', $folio->room->fresh()->status);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'pos.order_cancelled')->count());
        $audit = json_decode(DB::table('audit_logs')->where('event', 'pos.order_cancelled')->value('new_values'), true);
        $this->assertSame('Cancelled using X Order.', $audit['reason']);
        $this->assertSame(3, DB::table('inventory_events')->where('event_type', 'order_correction')->count());
    }

    public function test_addons_popup_has_collapsed_previous_orders_and_no_conversion_or_cancellation_reason(): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $dish = $this->item('Kitchen Dish', 'Favorites', true);
        $this->actingAs($actor)->post(route('folios.addon', $folio), $this->key() + ['pos_item_id' => $dish->id, 'quantity' => 1, 'panel' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('folios.orders.index', ['folio' => $folio, 'panel' => 1]));
        $this->get(route('folios.orders.index', ['folio' => $folio, 'panel' => 1]));

        $response = $this->get(route('folios.orders.index', ['folio' => $folio, 'panel' => 1]))
            ->assertSee('PREVIOUS ORDERS')->assertSee('ITEM DESCRIPTION')->assertSee('Cancel order')
            ->assertDontSee('Reason for cancelling order')->assertDontSee('CONVERT STAY PACKAGE')->assertDontSee('primaryNav');
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(1, $xpath->query('//details[@id="previousGuestOrders" and not(@open)]/summary')->length);
    }

    #[TestWith(['new', 901])]
    #[TestWith(['preparing', 0])]
    #[TestWith(['ready', 0])]
    #[TestWith(['delivered', 0])]
    public function test_expired_or_prepared_order_cancellation_keeps_the_bill_and_stock(string $status, int $elapsed): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $dish = $this->item('Porksilog', 'Breakfast', true);
        $this->actingAs($actor)->post(route('folios.addon', $folio), $this->key() + ['pos_item_id' => $dish->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $order->update(['status' => $status]);
        $this->travel($elapsed)->seconds();

        $this->post('/folios/'.$folio->id.'/orders/'.$order->id.'/cancel', $this->key() + ['reason' => 'Cancel'])->assertSessionHasErrors('cancel');

        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame('25.00', $folio->fresh()->pos_total);
        $this->assertSame(9, $dish->fresh()->stock_quantity);
    }

    public function test_wrong_stay_or_paid_order_cannot_be_cancelled(): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $dish = $this->item('Kitchen Dish', 'Favorites', true);
        $this->actingAs($actor)->post(route('folios.addon', $folio), $this->key() + ['pos_item_id' => $dish->id, 'quantity' => 1]);
        $order = Order::firstOrFail();
        $other = $this->folio($actor);
        $this->post('/folios/'.$other->id.'/orders/'.$order->id.'/cancel', $this->key() + ['reason' => 'Cancel'])->assertNotFound();
        PaymentEntry::create(['order_id' => $order->id, 'user_id' => $actor->id, 'kind' => 'pos_sale', 'cash_amount' => 25, 'gcash_amount' => 0, 'posted_at' => now()]);
        $this->post('/folios/'.$folio->id.'/orders/'.$order->id.'/cancel', $this->key() + ['reason' => 'Cancel'])->assertSessionHasErrors('cancel');
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame(9, $dish->fresh()->stock_quantity);
    }

    public function test_cancellation_requires_a_reason_and_folio_permission(): void
    {
        $actor = $this->actor('admin');
        $folio = $this->folio($actor);
        $this->actingAs($actor)->post('/folios/'.$folio->id.'/cancel', $this->key())->assertSessionHasErrors('reason');
        $kitchen = User::factory()->create();
        $kitchen->assignRole(Role::findOrCreate('kitchen', 'web'));
        $this->actingAs($kitchen)->post('/folios/'.$folio->id.'/cancel', $this->key() + ['reason' => 'Cancel'])->assertForbidden();
        $this->assertSame('active', $folio->fresh()->status);
    }

    public function test_recorded_payment_blocks_stay_cancellation_even_without_a_deposit_field(): void
    {
        $actor = $this->actor('admin');
        $folio = $this->folio($actor);
        PaymentEntry::create(['folio_id' => $folio->id, 'user_id' => $actor->id, 'kind' => 'deposit', 'cash_amount' => 500, 'gcash_amount' => 0, 'posted_at' => now()]);

        $this->actingAs($actor)->post('/folios/'.$folio->id.'/cancel', $this->key() + ['reason' => 'Cancel'])->assertSessionHasErrors('cancel');

        $this->assertSame('active', $folio->fresh()->status);
        $this->assertSame('occupied', $folio->room->fresh()->status);
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'cash_amount' => 500]);
    }

    public function test_mismatched_bill_rolls_back_all_order_cancellation_and_stock_returns(): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $first = $this->item('Kitchen Dish One', 'Favorites', true);
        $second = $this->item('Kitchen Dish Two', 'Favorites', true);
        $this->actingAs($actor)->post(route('folios.addon', $folio), $this->key() + ['items' => [['id' => $first->id, 'qty' => 1], ['id' => $second->id, 'qty' => 1]]]);
        $order = Order::firstOrFail();
        $folio->refresh();
        $payload = $folio->pos_items;
        unset($payload[1]);
        $folio->update(['pos_items' => $payload]);

        $this->post('/folios/'.$folio->id.'/orders/'.$order->id.'/cancel', $this->key() + ['reason' => 'Cancel'])->assertConflict();

        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame(2, (int) $order->items()->sum('quantity'));
        $this->assertSame(9, $first->fresh()->stock_quantity);
        $this->assertSame(9, $second->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('inventory_events')->where('event_type', 'order_correction')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'pos.order_cancelled')->count());
    }

    public function test_twelve_hour_stay_converts_to_twenty_four_from_original_arrival(): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $folio->update(['rate_tier' => '12h', 'room_charge' => 1195, 'expected_checkout_at' => '2026-10-14 00:00:00']);
        $this->actingAs($actor)->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '24h', 'reason' => 'Guest requested another twelve hours.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '24h', 'room_charge' => 2100, 'expected_checkout_at' => '2026-10-14 12:00:00']);
        $values = json_decode(DB::table('audit_logs')->where('event', 'folio.package_converted')->value('new_values'), true);
        $this->assertSame('12h', $values['before']['rate_tier']);
        $this->assertSame('2026-10-13 12:00:00', $folio->fresh()->checked_in_at->format('Y-m-d H:i:s'));
    }

    #[TestWith(['3h'])]
    #[TestWith(['4h'])]
    #[TestWith(['6h'])]
    public function test_short_stays_offer_and_apply_twelve_hour_conversion_with_discounts_and_add_ons(string $tier): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $folio->update(['rate_tier' => $tier, 'extra_hours' => 2, 'discount_type' => 'DC',
            'pos_items' => [['name' => 'Meal', 'qty' => 1, 'price' => 150, 'subtotal' => 150]]]);
        $this->actingAs($actor)->get(route('dashboard'))->assertViewHas('folioExtensionOptions', function (array $options) use ($folio): bool {
            return $options[$folio->id]['conversions']['12h']['rate'] === 1195.0
                && $options[$folio->id]['conversions']['12h']['checkout'] === '10/14/2026 12:00 AM';
        });

        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Guest requested twelve-hour package.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '12h', 'room_charge' => 1195,
            'extra_hours' => 0, 'discount_amount' => 55, 'net_total' => 1290, 'expected_checkout_at' => '2026-10-14 00:00:00']);
        $this->assertSame('Meal', $folio->fresh()->pos_items[0]['name']);
        $this->actingAs($actor)->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => ! isset($options[$folio->id]['conversions']['12h']) && isset($options[$folio->id]['conversions']['24h']));
        $this->assertDatabaseHas('audit_logs', ['event' => 'folio.package_converted', 'auditable_id' => $folio->id]);
    }

    #[TestWith(['3h'])]
    #[TestWith(['4h'])]
    #[TestWith(['6h'])]
    #[TestWith(['promo'])]
    public function test_short_stays_can_convert_directly_to_twenty_four_hours(string $tier): void
    {
        $actor = $this->actor();
        $folio = $this->folio($actor);
        $folio->update(['rate_tier' => $tier]);

        $this->actingAs($actor)->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => isset($options[$folio->id]['conversions']['12h'], $options[$folio->id]['conversions']['24h']));
        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '24h', 'reason' => 'Guest requested a day package.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '24h', 'room_charge' => 2100,
            'expected_checkout_at' => '2026-10-14 12:00:00']);
        $this->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => $options[$folio->id]['conversions'] === []);
    }

    #[TestWith(['folios.receipt', false])]
    #[TestWith(['folios.receipt.print', true])]
    #[TestWith(['folios.gate_pass', false])]
    public function test_cancelled_stay_cannot_issue_settlement_documents(string $route, bool $post): void
    {
        $actor = $this->actor('admin');
        $folio = $this->folio($actor);
        $this->actingAs($actor)->post(route('folios.cancel', $folio), $this->key() + ['reason' => 'Guest cancelled.'])
            ->assertSessionHasNoErrors();

        $response = $post
            ? $this->post(route($route, $folio), $this->key())
            : $this->get(route($route, $folio));

        $response->assertStatus(409);
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'status' => 'voided', 'transaction_id' => 'STAY-20261013-001']);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'receipt.print_requested')->count());
    }

    private function actor(string $role = 'cashier'): User
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'view_dashboard', 'post_pos_sales'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        return $actor;
    }

    private function folio(User $actor): Folio
    {
        $room = Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'occupied',
            'base_rate_3h' => 395, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100, 'base_rate_6h' => 785, 'base_rate_promo' => 995]);

        return Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Cancellation Guest', 'headcount' => 1])->id,
            'user_id' => $actor->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(3), 'room_charge' => 395, 'status' => 'active', 'pos_items' => []]);
    }

    private function item(string $name, string $category, bool $kitchen): PosItem
    {
        return PosItem::create(['name' => $name, 'category' => $category, 'price' => 25, 'stock_quantity' => 10, 'is_tracked' => true, 'is_available' => true, 'is_retired' => false, 'kitchen_hours_only' => $kitchen]);
    }

    /** @return array{operation_key: string} */
    private function key(): array
    {
        return ['operation_key' => (string) Str::uuid()];
    }
}
