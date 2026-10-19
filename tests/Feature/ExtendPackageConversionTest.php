<?php

namespace Tests\Feature;

use App\Models\DiscountRate;
use App\Models\Folio;
use App\Models\Room;
use App\Models\User;
use App\Services\DiscountService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExtendPackageConversionTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['6', 'dc', 2100, 95])]
    #[TestWith(['6', 'senior', 2100, 340])]
    #[TestWith(['6', 'pwd', 2100, 340])]
    #[TestWith(['13', 'dc', 2300, 105])]
    #[TestWith(['13', 'senior', 2300, 375])]
    #[TestWith(['13', 'pwd', 2300, 375])]
    #[TestWith(['1', 'dc', 2500, 115])]
    #[TestWith(['1', 'senior', 2500, 460])]
    #[TestWith(['1', 'pwd', 2500, 460])]
    public function test_cashier_converts_twelve_hours_to_twenty_four_and_keeps_original_discounts_and_addons(string $number, string $type, int $rate, int $discount): void
    {
        $this->withoutVite();
        $this->travelTo('2026-10-13 08:00:00');
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', $number)->firstOrFail();
        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 1, 'rate_tier' => '12h'])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->sole();
        $this->post(route('folios.discount', $folio), ['discount_type' => $type, 'discount_id_ref' => 'ID-24H'])->assertSessionHasNoErrors();
        $folio->update(['extra_hours' => 2, 'expected_checkout_at' => now()->addHours(14), 'extra_persons' => 1,
            'pos_items' => [['name' => 'Water', 'qty' => 2, 'price' => 25, 'subtotal' => 50]], 'security_deposit' => 100, 'deposit_status' => 'applied_to_bill']);
        $folio->recalculate();
        $this->get(route('dashboard'))->assertSee('Convert to 24-hour package')
            ->assertViewHas('folioExtensionOptions', fn (array $options): bool => $options[$folio->id]['canConvert'] === true && $options[$folio->id]['conversionRate'] === (float) $rate && $options[$folio->id]['conversionCheckout'] === '10/14/2026 08:00 AM');
        $data = ['operation_key' => (string) Str::uuid(), 'rate_tier' => '24h', 'reason' => 'Guest requested a 24-hour package.'];

        $this->post(route('folios.convert', $folio), $data)->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $this->post(route('folios.convert', $folio), $data)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '24h', 'room_charge' => $rate, 'discount_amount' => $discount,
            'extra_hours' => 0, 'pos_total' => 50, 'security_deposit' => 100, 'net_total' => $rate - $discount + 100, 'expected_checkout_at' => '2026-10-14 08:00:00']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'folio.package_converted')->count());
        $this->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => $options[$folio->id]['canConvert'] === false);
        $this->get(route('folios.receipt', $folio))->assertViewHas('totalDiscount', (float) $discount)->assertViewHas('finalBalanceToPay', (float) ($rate - $discount + 100));
    }

    #[TestWith(['12h', '12h'])]
    #[TestWith(['24h', '12h'])]
    #[TestWith(['24h', '24h'])]
    public function test_same_or_shorter_package_conversion_preserves_extensions_and_pricing(string $currentTier, string $targetTier): void
    {
        $this->withoutVite();
        $this->travelTo('2026-10-13 08:00:00');
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();
        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 1, 'rate_tier' => $currentTier])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->sole();
        $this->post(route('folios.extend', $folio), ['hours' => 2, 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $before = $folio->fresh()->getAttributes();

        $this->post(route('folios.convert', $folio), ['operation_key' => (string) Str::uuid(), 'rate_tier' => $targetTier, 'reason' => 'Guest requested a package change'])
            ->assertSessionHasErrors(['rate_tier' => 'Choose a package longer than the current stay package.']);

        $this->assertSame($before, $folio->fresh()->getAttributes());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'folio.package_converted')->count());
    }

    public function test_midnight_promo_can_convert_to_twelve_hours_from_original_arrival(): void
    {
        $this->withoutVite();
        $this->travelTo('2026-10-13 20:00:00');
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();
        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 1, 'rate_tier' => 'promo'])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->sole();

        $this->post(route('folios.convert', $folio), ['operation_key' => (string) Str::uuid(), 'rate_tier' => '12h', 'reason' => 'Guest requested a twelve-hour package'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '12h', 'room_charge' => 1195,
            'checked_in_at' => '2026-10-13 20:00:00', 'expected_checkout_at' => '2026-10-14 08:00:00']);
    }

    #[TestWith([null, 340.0])]
    #[TestWith([40.0, 40.0])]
    public function test_conversion_preserves_the_recorded_schedule_and_existing_custom_discount(?float $customDiscount, float $expectedDiscount): void
    {
        $this->withoutVite();
        $this->travelTo('2026-10-13 08:00:00');
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '6')->firstOrFail();
        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 1, 'rate_tier' => '12h', 'is_senior' => true, 'discount_id_ref' => 'OSCA-ORIGINAL'])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->sole();
        $folio->update(['discount_override_amount' => $customDiscount]);
        $snapshot = $folio->discount_schedule;
        DiscountRate::where(['discount_type' => 'SENIOR', 'room_tier' => 'CLASSIC', 'duration' => '24h'])->update(['amount' => 999]);
        app(DiscountService::class)->forgetRates();

        $this->post(route('folios.convert', $folio), ['operation_key' => (string) Str::uuid(), 'rate_tier' => '24h', 'reason' => 'Guest requested a longer package'])
            ->assertSessionHasNoErrors();

        $this->assertSame($snapshot, $folio->fresh()->discount_schedule);
        $this->assertSame($expectedDiscount, (float) $folio->fresh()->discount_amount);
        $this->assertSame(2100.0 - $expectedDiscount, (float) $folio->fresh()->net_total);
        $this->assertSame($customDiscount, $folio->fresh()->discount_override_amount === null ? null : (float) $folio->fresh()->discount_override_amount);
    }
}
