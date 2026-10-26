<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_discount_reference_is_data_rather_than_executable_inline_javascript(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('status', 'available')->where('is_staff_quarters', false)->firstOrFail();
        $room->update(['status' => 'occupied']);
        $reference = "OSCA');alert('injected');//<img src=x onerror=alert(1)>";
        $guest = Guest::create(['name' => '<img src=x onerror=alert(2)>', 'contact' => 'TEST-ESCAPING', 'headcount' => 2]);
        Folio::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'rate_tier' => '3h',
            'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(3),
            'discount_type' => 'SENIOR',
            'discount_id_ref' => $reference,
        ]);

        $response = $this->actingAs($cashier)->get(route('dashboard'))->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $button = $xpath->query('//button[@data-discount-ref]')->item(0);

        $this->assertNotNull($button);
        $this->assertSame($reference, $button->getAttribute('data-discount-ref'));
        $this->assertStringNotContainsString($reference, $button->getAttribute('onclick'));
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
    }

    public function test_both_dashboard_layouts_offer_the_configured_six_hour_rate(): void
    {
        $room = Room::where('status', 'available')->where('is_staff_quarters', false)->firstOrFail();
        $room->update(['base_rate_3h' => 395, 'base_rate_6h' => 777]);

        foreach (['pau@sedonapms.com', 'owner@sedonapms.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $xpath = $this->htmlXPath($response->getContent());
            $control = $xpath->query('//*[@data-rate-6h and @data-room-id="'.$room->id.'"]')->item(0);

            $this->assertNotNull($control);
            $this->assertSame('777.00', $control->getAttribute('data-rate-6h'));
        }
    }

    public function test_kitchen_tv_exposes_each_ticket_transition_in_a_shared_room_group(): void
    {
        $kitchen = User::where('email', 'kitchen1@sedonapms.com')->firstOrFail();
        $room = Room::firstOrFail();
        $first = Order::create(['user_id' => $kitchen->id, 'room_id' => $room->id, 'type' => 'room_charge', 'transaction_id' => 'TV-ONE', 'status' => 'new', 'total' => 100]);
        $second = Order::create(['user_id' => $kitchen->id, 'room_id' => $room->id, 'type' => 'room_charge', 'transaction_id' => 'TV-TWO', 'status' => 'ready', 'total' => 100]);

        $response = $this->actingAs($kitchen)->get(route('kitchen.tv'))->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $firstForm = $xpath->query('//form[@action="'.route('pos.order.status', $first).'"]')->item(0);
        $secondForm = $xpath->query('//form[@action="'.route('pos.order.status', $second).'"]')->item(0);

        $this->assertNotNull($firstForm);
        $this->assertNotNull($secondForm);
        $this->assertSame('preparing', $xpath->query('.//input[@name="status"]', $firstForm)->item(0)->getAttribute('value'));
        $this->assertSame('delivered', $xpath->query('.//input[@name="status"]', $secondForm)->item(0)->getAttribute('value'));
    }

    public function test_pos_menu_escapes_names_and_allows_stocked_kitchen_dishes_at_night(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $name = "Guest's <img src=x onerror=alert(1)>";
        $safeItem = PosItem::create(['name' => $name, 'category' => 'Drinks', 'price' => 50, 'is_available' => true, 'is_tracked' => false, 'kitchen_hours_only' => false]);
        $kitchenItem = PosItem::create(['name' => 'Night Kitchen Dish', 'category' => 'Favorites', 'price' => 100, 'is_available' => true, 'is_tracked' => true, 'stock_quantity' => 50, 'kitchen_hours_only' => true]);

        $response = $this->actingAs($cashier)->get(route('pos.index'))->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $button = $xpath->query('//button[@data-item-id="'.$safeItem->id.'"]')->item(0);

        $this->assertSame('Point of Sale & Dining', trim($xpath->query('//h1')->item(0)->textContent));
        $this->assertNotNull($button);
        $this->assertSame($name, $button->getAttribute('data-item-name'));
        $this->assertStringNotContainsString($name, $button->getAttribute('onclick'));
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-item-id="'.$kitchenItem->id.'"]')->length);
        $stockBadge = $xpath->query('//div[contains(@class,"pos-catalog-item")][.//h3[text()="Night Kitchen Dish"]]//span[contains(text(),"Stock:")]')->item(0);
        $this->assertStringContainsString('bg-emerald-100', $stockBadge->getAttribute('class'));
    }

    public function test_pricing_form_keeps_manual_availability_separate_from_ingredient_stock(): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        PosItem::where('name', 'Egg (Fried/Boiled)')->update(['stock_quantity' => 0]);
        $dish = PosItem::where('name', 'Chicksilog')->firstOrFail();
        $dish->update(['is_available' => true]);

        $response = $this->actingAs($owner)->get(route('admin.pricing.index'))->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $formId = 'itemPricing'.$dish->id;
        $checkbox = $xpath->query('//input[@form="'.$formId.'" and @type="checkbox"]')->item(0);
        $unchecked = $xpath->query('//input[@form="'.$formId.'" and @name="is_available" and @type="hidden"]')->item(0);

        $this->assertNotNull($checkbox);
        $this->assertTrue($checkbox->hasAttribute('checked'));
        $this->assertNotNull($unchecked);
        $this->assertSame('0', $unchecked->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//form[@id="'.$formId.'"]')->length);
    }

    public function test_staff_directory_escapes_modal_names_and_has_no_shared_password_default(): void
    {
        $owner = User::where('email', 'owner@sedonapms.com')->firstOrFail();
        $name = "O'Reilly <img src=x onerror=alert(1)>";
        $staff = User::factory()->create(['name' => $name, 'is_active' => false]);
        $staff->assignRole('cashier');

        $response = $this->actingAs($owner)->get(route('admin.users.index'))->assertOk()->assertSee('Deactivated');
        $xpath = $this->htmlXPath($response->getContent());
        $button = $xpath->query('//button[@data-user-id="'.$staff->id.'"]')->item(0);

        $this->assertNotNull($button);
        $this->assertSame($name, $button->getAttribute('data-user-name'));
        $this->assertStringNotContainsString($name, $button->getAttribute('onclick'));
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="password" and @value]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('admin.users.destroy', $staff).'"]')->length);
    }

    public function test_booking_checkin_control_submits_the_reservation_and_escapes_guest_contact(): void
    {
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('status', 'available')->where('is_staff_quarters', false)->firstOrFail();
        $booking = Booking::create([
            'user_id' => $cashier->id,
            'room_id' => $room->id,
            'guest_name' => "O'Reilly <img src=x onerror=alert(1)>",
            'guest_contact' => "');alert('contact');//",
            'headcount' => 2,
            'rate_tier' => '6h',
            'status' => 'scheduled',
            'arrival_at' => now(),
            'departure_at' => now()->addHours(6),
        ]);

        $response = $this->actingAs($cashier)->get(route('bookings.index'))->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $form = $xpath->query('//form[@action="'.route('checkin.store').'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $payload = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $payload[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $this->assertSame($booking->guest_contact, $payload['guest_contact']);
        $this->post($form->getAttribute('action'), $payload)->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'checked_in']);
        $this->assertDatabaseHas('folios', ['booking_id' => $booking->id, 'room_id' => $room->id, 'rate_tier' => '6h', 'status' => 'active']);
    }

    private function htmlXPath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
