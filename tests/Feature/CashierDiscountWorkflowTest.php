<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CashierDiscountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_validation_is_visible_after_returning_to_the_front_desk(): void
    {
        $folio = $this->checkInRoom29();

        $this->from(route('dashboard'))->followingRedirects()->post(route('folios.discount', $folio), [
            'discount_type' => 'senior', 'discount_id_ref' => ' ',
        ])->assertSee('Record the statutory discount ID reference.');

        $this->assertSame('NONE', $folio->fresh()->discount_type);
        $this->assertSame('0.00', $folio->fresh()->discount_amount);
    }

    #[TestWith(['senior', 'OSCA-ROOM-29', '79.00', '316.00'])]
    #[TestWith(['pwd', 'PWD-ROOM-29', '79.00', '316.00'])]
    #[TestWith(['dc', 'DC-ROOM-29', '40.00', '355.00'])]
    public function test_cashier_can_save_a_documented_fixed_discount_from_checkout(string $type, string $reference, string $discount, string $total): void
    {
        $folio = $this->checkInRoom29();

        $this->postJson(route('folios.deposit', $folio), [
            'discount_type' => $type, 'discount_id_ref' => $reference,
        ])->assertOk()->assertJsonPath('discount_amount', $discount)->assertJsonPath('net_total', $total);

        $this->assertDatabaseHas('folios', [
            'id' => $folio->id, 'discount_type' => strtoupper($type),
            'discount_id_ref' => $reference, 'discount_amount' => (float) $discount,
        ]);
        $this->get(route('checkout.process', $folio))->assertSee($reference);
    }

    public function test_cashier_can_apply_switch_and_clear_fixed_dc_without_an_approval(): void
    {
        $folio = $this->checkInRoom29();
        $cashier = auth()->user();
        $url = route('folios.discount', $folio);
        $payload = ['discount_type' => 'dc', 'discount_id_ref' => 'DC-CARD-29'];

        $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->post($url, $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'discount_type' => 'DC', 'discount_amount' => 40, 'net_total' => 355, 'discount_id_ref' => 'DC-CARD-29']);
        $this->assertDatabaseCount('approval_requests', 0);
        $audit = DB::table('audit_logs')->where('event', 'folio.discount_applied')->sole();
        $details = json_decode($audit->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($cashier->id, $audit->user_id);
        $this->assertSame('NONE', $details['before']['discount_type']);
        $this->assertSame('DC', $details['after']['discount_type']);
        $this->assertSame('40.00', $details['after']['discount_amount']);

        $this->post($url, ['discount_type' => 'senior', 'discount_id_ref' => 'OSCA-29'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'discount_type' => 'SENIOR', 'discount_amount' => 79, 'net_total' => 316]);
        $this->post($url, ['discount_type' => 'dc', 'discount_id_ref' => 'DC-CARD-29'])->assertSessionHasNoErrors();
        $this->post($url, ['discount_type' => 'none'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'discount_type' => 'NONE', 'discount_amount' => 0, 'net_total' => 395, 'discount_id_ref' => null]);
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_checkout_discount_without_an_id_returns_an_actionable_error_and_keeps_the_bill(): void
    {
        $folio = $this->checkInRoom29();

        $this->postJson(route('folios.deposit', $folio), [
            'discount_type' => 'pwd', 'discount_id_ref' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('discount_id_ref')
            ->assertJsonPath('errors.discount_id_ref.0', 'Record the statutory discount ID reference.');

        $this->assertSame('NONE', $folio->fresh()->discount_type);
        $this->assertSame('395.00', $folio->fresh()->net_total);
    }

    public function test_cashier_discount_change_link_selects_the_requested_guest_and_classification(): void
    {
        $folio = $this->checkInRoom29();

        $this->get(route('approvals.index', ['folio_id' => $folio->id, 'discount_type' => 'DC']))
            ->assertViewHas('selectedFolio', fn (Folio $selected): bool => $selected->is($folio))
            ->assertViewHas('selectedDiscountType', 'DC')->assertSee('Room 29');

        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertSame('NONE', $folio->fresh()->discount_type);
    }

    private function checkInRoom29(): Folio
    {
        $this->withoutVite();
        $this->travelTo('2026-10-11 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'pau@sedonapms.com')->firstOrFail());
        $room = Room::where('number', '29')->firstOrFail();

        $this->post(route('checkin.store'), [
            'room_id' => $room->id, 'guest_name' => 'Discount workflow guest',
            'headcount' => 2, 'rate_tier' => '3h',
        ])->assertSessionHasNoErrors();

        return Folio::where('room_id', $room->id)->firstOrFail();
    }
}
