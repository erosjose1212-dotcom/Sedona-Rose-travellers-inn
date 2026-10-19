<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatutoryDiscountEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, ?string}> */
    public static function missingReferences(): array
    {
        return ['senior without reference' => ['is_senior', null], 'PWD with whitespace reference' => ['is_pwd', '   ']];
    }

    #[DataProvider('missingReferences')]
    public function test_initial_statutory_discount_requires_evidence_without_creating_a_guest(string $flag, ?string $reference): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '5')->firstOrFail();
        $guestCount = Guest::count();
        $folioCount = Folio::count();

        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id, 'guest_name' => 'Missing statutory evidence', 'headcount' => 1,
            'rate_tier' => '3h', $flag => true, 'discount_id_ref' => $reference,
        ])->assertSessionHasErrors('discount_id_ref');

        $this->assertSame($guestCount, Guest::count());
        $this->assertSame($folioCount, Folio::count());
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_initial_statutory_discount_saves_the_reference(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '5')->firstOrFail();

        $this->actingAs($cashier)->post(route('checkin.store'), [
            'room_id' => $room->id, 'guest_name' => 'Documented discount', 'headcount' => 1,
            'rate_tier' => '3h', 'is_senior' => true, 'discount_id_ref' => 'OSCA-EVIDENCE-1',
        ])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['room_id' => $room->id, 'discount_type' => 'SENIOR', 'discount_id_ref' => 'OSCA-EVIDENCE-1']);
    }

    public function test_admin_offset_correction_is_stored_and_displayed_in_manila_time(): void
    {
        $this->withoutVite();
        $this->travelTo('2026-10-08 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '5')->firstOrFail();

        $this->actingAs($cashier)->post(route('checkin.store'), ['room_id' => $room->id, 'headcount' => 1, 'rate_tier' => '3h'])->assertSessionHasNoErrors();

        $folio = Folio::where('room_id', $room->id)->firstOrFail();
        $admin = User::where('email', 'admin@sedonapms.com')->firstOrFail();
        $this->actingAs($admin)->post('/admin/check-in-times/'.$folio->id, [
            'operation_key' => (string) Str::uuid(), 'expected_checked_in_at' => $folio->checked_in_at->toIso8601String(),
            'checked_in_at' => '2026-10-08T02:00:00.000Z', 'reason' => 'RA declaration time correction.',
        ])->assertSessionHasNoErrors();
        $folio->refresh();
        $this->assertSame('2026-10-08 10:00:00', $folio->checked_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 13:00:00', $folio->expected_checkout_at->format('Y-m-d H:i:s'));
    }

    public function test_legacy_statutory_discount_cannot_settle_without_evidence(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $cashier = User::where('email', 'pau@sedonapms.com')->firstOrFail();
        $room = Room::where('number', '5')->firstOrFail();
        $room->update(['status' => 'occupied']);
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Legacy discount'])->id,
            'user_id' => $cashier->id, 'transaction_id' => Folio::generateTransactionId(), 'status' => 'active',
            'checked_in_at' => now()->subHour(), 'expected_checkout_at' => now()->addHours(2), 'rate_tier' => '3h',
            'room_charge' => 395, 'discount_type' => 'SENIOR', 'senior_pwd_discount' => true]);
        Shift::create(['opened_by' => $cashier->id, 'opened_at' => now()->subHours(2), 'shift_date' => today(), 'shift_type' => 'day', 'opening_float' => 100]);
        $this->actingAs($cashier);

        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 395])->assertSessionHasErrors('discount_id_ref');

        $this->assertSame('active', $folio->fresh()->status);
        $this->assertDatabaseCount('payment_entries', 0);

        $this->post(route('checkout.submit', $folio), ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 395, 'discount_id_ref' => 'OSCA-LEGACY-REVIEW'])->assertSessionHasNoErrors();
        $this->assertSame('checked_out', $folio->fresh()->status);
        $this->assertSame('OSCA-LEGACY-REVIEW', $folio->fresh()->discount_id_ref);
        $this->assertDatabaseCount('payment_entries', 1);
    }
}
