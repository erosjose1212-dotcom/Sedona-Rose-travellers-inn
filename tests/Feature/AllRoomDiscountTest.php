<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AllRoomDiscountTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, array<string, int>, array<string, int>, array<string, int>}> */
    public static function originalPackages(): array
    {
        return [
            '3 hours' => ['3h', ['Classic Room' => 395, 'Premium Room' => 495, 'VIP Suite Room' => 695], ['Classic Room' => 40, 'Premium Room' => 50, 'VIP Suite Room' => 65], ['Classic Room' => 79, 'Premium Room' => 99, 'VIP Suite Room' => 139]],
            '4 hours, no configured deduction' => ['4h', ['Classic Room' => 525, 'Premium Room' => 625, 'VIP Suite Room' => 825], ['Classic Room' => 0, 'Premium Room' => 0, 'VIP Suite Room' => 0], ['Classic Room' => 0, 'Premium Room' => 0, 'VIP Suite Room' => 0]],
            '6 hours' => ['6h', ['Classic Room' => 785, 'Premium Room' => 885, 'VIP Suite Room' => 1085], ['Classic Room' => 0, 'Premium Room' => 0, 'VIP Suite Room' => 0], ['Classic Room' => 158, 'Premium Room' => 178, 'VIP Suite Room' => 218]],
            '12 hours' => ['12h', ['Classic Room' => 1195, 'Premium Room' => 1295, 'VIP Suite Room' => 1395], ['Classic Room' => 55, 'Premium Room' => 60, 'VIP Suite Room' => 70], ['Classic Room' => 195, 'Premium Room' => 215, 'VIP Suite Room' => 255]],
            '24 hours' => ['24h', ['Classic Room' => 2100, 'Premium Room' => 2300, 'VIP Suite Room' => 2500], ['Classic Room' => 95, 'Premium Room' => 105, 'VIP Suite Room' => 115], ['Classic Room' => 340, 'Premium Room' => 375, 'VIP Suite Room' => 460]],
            'midnight promo, original deduction' => ['promo', ['Classic Room' => 995, 'Premium Room' => 1055, 'VIP Suite Room' => 1155], ['Classic Room' => 40, 'Premium Room' => 50, 'VIP Suite Room' => 65], ['Classic Room' => 79, 'Premium Room' => 99, 'VIP Suite Room' => 139]],
        ];
    }

    /** @param array<string, int> $prices @param array<string, int> $dcAmounts @param array<string, int> $seniorAmounts */
    #[DataProvider('originalPackages')]
    public function test_every_guest_room_keeps_original_discounts_through_checkout_and_receipt(string $tier, array $prices, array $dcAmounts, array $seniorAmounts): void
    {
        $this->prepareCashier();
        config(['sedona.midnight_promo_enabled' => true]);
        $rooms = Room::where('is_staff_quarters', false)->orderByNumber()->get();
        $this->assertCount(31, $rooms);

        foreach ($rooms as $room) {
            foreach (['dc', 'senior', 'pwd'] as $type) {
                $room->update(['status' => 'available']);
                $this->post(route('checkin.store'), ['room_id' => $room->id, 'guest_name' => 'Room '.$room->number.' discount test', 'headcount' => 1, 'rate_tier' => $tier])
                    ->assertSessionHasNoErrors();
                $folio = Folio::where('room_id', $room->id)->where('status', 'active')->sole();
                $discount = $type === 'dc' ? $dcAmounts[$room->type] : $seniorAmounts[$room->type];
                $net = $prices[$room->type] - $discount;
                $reference = strtoupper($type).'-ROOM-'.$room->number;
                $payload = ['discount_type' => $type, 'discount_id_ref' => $reference];

                $this->post(route('folios.discount', $folio), $payload)->assertSessionHasNoErrors();
                $this->postJson(route('folios.deposit', $folio), $payload)->assertOk()
                    ->assertJsonPath('discount_amount', number_format($discount, 2, '.', ''))
                    ->assertJsonPath('net_total', number_format($net, 2, '.', ''));
                $this->get(route('checkout.process', $folio))->assertViewHas('discountOptions', [
                    'DC' => (float) $dcAmounts[$room->type], 'SENIOR' => (float) $seniorAmounts[$room->type], 'PWD' => (float) $seniorAmounts[$room->type],
                ])->assertViewHas('totalDiscount', (float) $discount)->assertViewHas('finalBalanceToPay', (float) $net);

                $this->post(route('checkout.submit', $folio), $payload + ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 3000])
                    ->assertSessionHasNoErrors();

                $this->assertDatabaseHas('folios', ['id' => $folio->id, 'status' => 'checked_out', 'room_charge' => $prices[$room->type], 'discount_type' => strtoupper($type), 'discount_id_ref' => $reference,
                    'discount_amount' => $discount, 'net_total' => $net, 'change_due' => 3000 - $net]);
                $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'kind' => 'settlement', 'cash_amount' => $net]);
                $this->get(route('folios.receipt', $folio))->assertViewHas('totalDiscount', (float) $discount)->assertViewHas('finalBalanceToPay', (float) $net)->assertViewHas('discountIdRef', $reference);
                $this->post(route('folios.discount', $folio), ['discount_type' => 'none'])->assertConflict();
            }
        }
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertDatabaseCount('payment_entries', 93);
    }

    #[TestWith(['NONE', null, false])]
    #[TestWith(['DC', null, false])]
    #[TestWith(['DC', 100.0, true])]
    public function test_checkout_enables_fixed_dc_selection_but_keeps_custom_discounts_protected(string $type, ?float $customAmount, bool $locked): void
    {
        $this->prepareCashier();
        $room = Room::where('number', '13')->firstOrFail();
        $this->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 1, 'rate_tier' => '3h'])->assertSessionHasNoErrors();
        $folio = Folio::where('room_id', $room->id)->sole();
        $folio->update(['discount_type' => $type, 'discount_override_amount' => $customAmount]);
        $folio->recalculate();

        $response = $this->get(route('checkout.process', $folio));
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $select = $xpath->query('//select[@id="discountTypeSelect"]')->item(0);
        $option = $xpath->query('//select[@id="discountTypeSelect"]/option[@value="dc"]')->item(0);

        $this->assertNotNull($select);
        $this->assertSame($locked, $select->hasAttribute('disabled'));
        $this->assertNotNull($option);
        $this->assertFalse($option->hasAttribute('disabled'));
        $response->assertDontSee('DC and approved custom discount changes need owner/admin approval.');
    }

    private function prepareCashier(): void
    {
        $this->withoutVite();
        $this->travelTo('2026-10-13 21:00:00');
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        Shift::create(['opened_by' => $cashier->id, 'opened_at' => now(), 'shift_date' => today(), 'shift_type' => 'day']);
        $this->actingAs($cashier);

    }
}
