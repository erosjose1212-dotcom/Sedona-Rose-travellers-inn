<?php

namespace Tests\Feature;

use App\Models\PosItem;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoomPricingTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['base_rate_3h'])]
    #[TestWith(['base_rate_4h'])]
    #[TestWith(['base_rate_6h'])]
    #[TestWith(['base_rate_12h'])]
    #[TestWith(['base_rate_24h'])]
    #[TestWith(['base_rate_promo'])]
    public function test_room_pricing_rejects_extreme_amounts_without_changing_rates(string $field): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();
        $rates = $room->only(['base_rate_3h', 'base_rate_4h', 'base_rate_6h', 'base_rate_12h', 'base_rate_24h', 'base_rate_promo']);

        $this->actingAs($admin)->postJson(route('admin.pricing.update'), array_replace($rates, ['room_id' => $room->id, $field => '1e309']))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($rates, $room->fresh()->only(array_keys($rates)));
    }

    public function test_catalog_pricing_rejects_extreme_amounts_without_changing_the_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $item = PosItem::firstOrFail();
        $before = $item->price;

        $this->actingAs($admin)->postJson(route('admin.pricing.update'), ['pos_item_id' => $item->id, 'price' => '1e309', 'is_available' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('price');

        $this->assertSame($before, $item->fresh()->price);
    }

    public function test_catalog_creation_rejects_extreme_amounts_without_creating_an_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $before = PosItem::count();

        $this->actingAs($admin)->postJson(route('admin.pricing.items.store'), ['name' => 'Extreme-price QA item', 'category' => 'Services', 'price' => '1e309', 'is_available' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('price');

        $this->assertDatabaseCount('pos_items', $before);
    }

    /** @param array{int, int, int, int, int, int} $rates */
    #[TestWith(['6', [395, 525, 785, 1195, 2100, 995]])]
    #[TestWith(['13', [495, 625, 885, 1295, 2300, 1055]])]
    #[TestWith(['1', [695, 825, 1085, 1395, 2500, 1155]])]
    public function test_room_rates_match_the_supplied_tariff(string $number, array $rates): void
    {
        $this->seed(DatabaseSeeder::class);
        $room = Room::where('number', $number)->firstOrFail();

        foreach (['3h', '4h', '6h', '12h', '24h', 'promo'] as $index => $tier) {
            $this->assertSame((float) $rates[$index], $room->getRateForTier($tier), $tier);
        }
        $this->assertDatabaseHas('pos_items', ['name' => 'Clean Bed Sheet (Single/Double)', 'price' => 100]);
    }

    public function test_room_rate_form_keeps_its_controls_and_saves_rates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();
        $response = $this->actingAs($admin)->get(route('admin.pricing.index'));
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[.//input[@name="room_id" and @value="'.$room->id.'"]]')->item(0);

        $this->assertNotNull($form);
        $this->assertSame('td', $form->parentNode->nodeName, 'Browsers need the form inside a table cell.');
        $formId = $form->getAttribute('id');
        $this->assertNotSame('', $formId);
        $payload = [];
        foreach ($xpath->query('//input[@form="'.$formId.'"] | //form[@id="'.$formId.'"]//input') as $input) {
            $payload[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertArrayHasKey('base_rate_4h', $payload);
        $this->assertArrayHasKey('base_rate_6h', $payload);
        $payload['base_rate_4h'] = 530;
        $payload['base_rate_6h'] = 790;

        $this->post($form->getAttribute('action'), $payload)
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Rates updated for Room 6.');

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'base_rate_4h' => 530, 'base_rate_6h' => 790]);
        $this->assertDatabaseHas('rooms', ['number' => '7', 'base_rate_6h' => 785]);
    }

    public function test_four_hour_check_in_uses_the_stored_price_and_duration(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();

        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id, 'guest_name' => 'Tariff Guest', 'headcount' => 2, 'rate_tier' => '4h',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('folios', ['room_id' => $room->id, 'rate_tier' => '4h', 'room_charge' => 525, 'expected_checkout_at' => '2026-10-12 14:00:00']);
    }

    public function test_four_hour_booking_uses_the_correct_departure(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();

        $this->actingAs($cashier)->post(route('bookings.store'), [
            'room_id' => $room->id, 'guest_name' => 'Tariff Guest', 'headcount' => 2,
            'rate_tier' => '4h', 'arrival_at' => '2026-10-13 10:00:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('bookings.index'));

        $this->assertDatabaseHas('bookings', ['room_id' => $room->id, 'rate_tier' => '4h', 'departure_at' => '2026-10-13 14:00:00']);
    }

    public function test_tariff_upgrade_preserves_room_status_and_staff_quarters(): void
    {
        $this->seed(DatabaseSeeder::class);
        $room = Room::where('number', '6')->firstOrFail();
        $room->update(['status' => 'occupied', 'notes' => 'Keep this note', 'base_rate_6h' => 790]);
        $migration = require database_path('migrations/2026_10_13_100047_update_sedona_room_tariff.php');

        $migration->up();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'occupied', 'notes' => 'Keep this note', 'base_rate_4h' => 525, 'base_rate_6h' => 785]);
        $this->assertDatabaseHas('rooms', ['number' => '12', 'base_rate_3h' => 0, 'base_rate_4h' => 0, 'base_rate_6h' => 0]);
    }

    public function test_negative_four_hour_rate_leaves_room_prices_unchanged(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.pricing.update'), [
            'room_id' => $room->id, 'base_rate_3h' => 395, 'base_rate_4h' => -1,
            'base_rate_6h' => 785, 'base_rate_12h' => 1195, 'base_rate_24h' => 2100, 'base_rate_promo' => 995,
        ])->assertSessionHasErrors('base_rate_4h');

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'base_rate_4h' => 525, 'base_rate_6h' => 785]);
    }

    public function test_catalog_form_submits_the_price_and_unchecked_availability(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $item = PosItem::where('name', 'Clean Bed Sheet (Single/Double)')->firstOrFail();
        $response = $this->actingAs($admin)->get(route('admin.pricing.index'));
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $formId = 'itemPricing'.$item->id;
        $form = $xpath->query('//form[@id="'.$formId.'"]')->item(0);

        $this->assertNotNull($form);
        $this->assertSame('td', $form->parentNode->nodeName);
        $this->assertSame(1, $xpath->query('//button[@type="submit" and @form="'.$formId.'"] | //form[@id="'.$formId.'"]//button[@type="submit"]')->length);
        $payload = [];
        foreach ($xpath->query('//input[@form="'.$formId.'" and @type!="checkbox"] | //form[@id="'.$formId.'"]//input') as $input) {
            $payload[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertArrayHasKey('price', $payload);
        $this->assertSame('0', $payload['is_available']);

        $this->post($form->getAttribute('action'), $payload)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('pos_items', ['id' => $item->id, 'price' => 100, 'is_available' => false]);
    }
}
