<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashierShiftGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_closed_shift_explains_pending_handover_and_keeps_checkout_unsettled(): void
    {
        config(['sedona.shift_handover_enabled' => true]);
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $this->shift($cashier, ['closed_at' => now(), 'closed_by' => $cashier->id, 'handover_status' => 'pending']);

        $this->actingAs($cashier)->from(route('checkout.process', $folio))
            ->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])
            ->assertRedirect(route('checkout.process', $folio))
            ->assertSessionHasErrors(['shift' => 'The previous shift is closed and awaiting handover. A different cashier must count and accept the drawer in Shifts & Drawer before payments can be recorded.']);

        $this->assertSame('active', $folio->fresh()->status);
        $this->assertSame('occupied', $folio->room->fresh()->status);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertDatabaseCount('financial_operations', 0);
        $response = $this->get(route('checkout.process', $folio))->assertSee('Waiting for drawer handover');
        $this->assertCheckoutDisabled($response->getContent(), true);
        $this->assertSame(0, $this->htmlXPath($response->getContent())->query('//form[@id="checkoutStartShiftForm"]')->length);
        $this->get(route('shifts.index'))->assertSee('Another cashier must accept your closed drawer')->assertDontSee('Start cashier shift');
    }

    public function test_checkout_offers_shift_start_only_when_no_drawer_or_handover_blocks_it(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);

        $response = $this->actingAs($cashier)->get(route('checkout.process', $folio))->assertSee('Start cashier shift');

        $this->assertCheckoutDisabled($response->getContent(), true);
        $form = $this->htmlXPath($response->getContent())->query('//form[@id="checkoutStartShiftForm"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(route('shifts.open'), $form->getAttribute('action'));
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_starting_a_shift_from_checkout_returns_to_the_stay_and_records_payment_in_that_drawer(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);

        $this->actingAs($cashier)->post(route('shifts.open'), [
            'operation_key' => (string) Str::uuid(), 'opening_float' => 0, 'checkout_folio_id' => $folio->id,
        ])->assertRedirect(route('checkout.process', $folio))->assertSessionHasNoErrors();

        $shift = Shift::sole();
        $this->assertSame('0.00', $shift->opening_float);
        $response = $this->get(route('checkout.process', $folio))->assertSee('Cashier shift active');
        $this->assertCheckoutDisabled($response->getContent(), false);
        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])
            ->assertSessionHasNoErrors();
        $this->assertSame('checked_out', $folio->fresh()->status);
        $this->assertDatabaseHas('payment_entries', ['folio_id' => $folio->id, 'shift_id' => $shift->id, 'cash_amount' => 395]);
    }

    public function test_frozen_drawer_explains_the_review_needed_and_keeps_payment_unposted(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $this->shift($cashier, ['is_frozen' => true]);

        $response = $this->actingAs($cashier)->get(route('checkout.process', $folio))->assertSee('Drawer frozen');

        $this->assertCheckoutDisabled($response->getContent(), true);
        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'cash_tendered' => 395])
            ->assertSessionHasErrors(['shift' => 'The current drawer is frozen. Ask an admin or owner to review its status in Shifts & Drawer before recording payments.']);
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame('active', $folio->fresh()->status);
    }

    public function test_another_cashiers_drawer_is_identified_before_payment_is_attempted(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $other = User::where('email', 'raquel@sedonapms.com')->firstOrFail();
        $this->shift($other);

        $response = $this->actingAs($cashier)->get(route('checkout.process', $folio))->assertSee('Drawer assigned to another cashier')->assertSee($other->name);

        $this->assertCheckoutDisabled($response->getContent(), true);
        $this->assertSame(0, $this->htmlXPath($response->getContent())->query('//form[@id="checkoutStartShiftForm"]')->length);
    }

    public function test_shift_start_requires_the_actual_opening_cash_and_does_not_invent_a_float(): void
    {
        $this->actingAs($this->cashier())->post(route('shifts.open'), ['operation_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['opening_float' => 'Enter the cash actually in the drawer. Enter 0 if it is empty.']);

        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    public function test_invalid_checkout_return_does_not_start_a_shift(): void
    {
        $this->actingAs($this->cashier())->post(route('shifts.open'), [
            'operation_key' => (string) Str::uuid(), 'opening_float' => 100, 'checkout_folio_id' => 999999,
        ])->assertSessionHasErrors('checkout_folio_id');

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_settled_checkout_return_does_not_start_a_shift(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $folio->update(['status' => 'checked_out', 'checked_out_at' => now()]);

        $this->actingAs($cashier)->post(route('shifts.open'), [
            'operation_key' => (string) Str::uuid(), 'opening_float' => 100, 'checkout_folio_id' => $folio->id,
        ])->assertSessionHasErrors(['checkout_folio_id' => 'This stay has already been settled. Start your shift from Shifts & Drawer.']);

        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    public function test_checkout_shift_start_requires_payment_access(): void
    {
        $cashier = $this->cashier();
        $folio = $this->folio($cashier);
        $cashier->syncRoles([]);
        $cashier->givePermissionTo(['manage_shifts', 'manage_folios']);

        $this->actingAs($cashier)->post(route('shifts.open'), [
            'operation_key' => (string) Str::uuid(), 'opening_float' => 100, 'checkout_folio_id' => $folio->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('financial_operations', 0);
    }

    private function cashier(): User
    {
        return User::where('email', 'pau@sedonapms.com')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function shift(User $cashier, array $attributes = []): Shift
    {
        return Shift::create(['opened_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => today(),
            'opened_at' => now()->subHour(), 'opening_float' => 100, ...$attributes]);
    }

    private function folio(User $cashier): Folio
    {
        $room = Room::where('number', '1')->firstOrFail();
        $room->update(['status' => 'occupied']);

        return Folio::create(['user_id' => $cashier->id, 'room_id' => $room->id,
            'guest_id' => Guest::create(['name' => 'Shift Guidance Guest'])->id,
            'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'checked_in_at' => now(),
            'expected_checkout_at' => now()->addHours(3), 'room_charge' => 395, 'status' => 'active']);
    }

    private function assertCheckoutDisabled(string $html, bool $disabled): void
    {
        $button = $this->htmlXPath($html)->query('//button[@id="completeCheckoutButton"]')->item(0);
        $this->assertNotNull($button);
        $this->assertSame($disabled, $button->hasAttribute('disabled'));
    }

    private function htmlXPath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
