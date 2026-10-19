<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckoutReceiptSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00:00');
    }

    public function test_check_ins_and_previews_do_not_allocate_receipts(): void
    {
        $actor = $this->cashier();
        $folio = $this->checkIn($actor);

        $this->assertSame('STAY-20261015-001', $folio->transaction_id);
        $this->assertNull($folio->receipt_number);
        $this->get(route('folios.receipt', $folio))->assertSee('Not issued');
        $this->get(route('folios.billing', $folio))->assertOk();
        $this->post(route('folios.receipt.print', $folio), ['operation_key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(0, DB::table('document_sequences')->where('name', 'like', 'scti:%')->count());
    }

    public function test_receipts_follow_checkout_order_even_when_check_in_order_is_reversed(): void
    {
        $actor = $this->cashier();
        $first = $this->checkIn($actor);
        $second = $this->checkIn($actor);

        $this->checkout($second);
        $this->checkout($first);

        $this->assertSame('SCTI-20261015-001', $second->fresh()->receipt_number);
        $this->assertSame('SCTI-20261015-002', $first->fresh()->receipt_number);
        $this->assertSame('STAY-20261015-001', $first->fresh()->transaction_id);
        $this->get(route('folios.receipt', $second))->assertSee('Receipt #:')->assertSee('SCTI-20261015-001');
        $this->get(route('folios.billing', $first))->assertSee('SCTI-20261015-002');
        $this->get(route('receipts.index'))->assertViewHas('folios', fn ($folios): bool => $folios->modelKeys() === [$second->id, $first->id])
            ->assertViewHas('receiptCount', 2)->assertViewHas('guestCount', 4);
        $this->get(route('transactions.index', ['q' => 'SCTI-20261015-001']))->assertViewHas('transactions', fn ($rows): bool => $rows->total() === 1);
        $this->get(route('transactions.index', ['q' => $second->transaction_id]))->assertViewHas('transactions', fn ($rows): bool => $rows->total() === 1);
    }

    public function test_guest_receipt_keeps_scti_and_the_top_logo_without_barcodes_or_facebook_qr(): void
    {
        $actor = $this->cashier();
        $folio = $this->checkIn($actor);
        $this->checkout($folio);

        $this->get(route('folios.receipt', $folio))->assertSee('SCTI-20261015-001')
            ->assertDontSee($folio->transaction_id)->assertDontSee('Stay ref:')
            ->assertSee('billing-sedona-logo.png')->assertDontSee('billing-facebook-qr.svg')
            ->assertDontSee('Scan to visit our Facebook page')
            ->assertDontSee('<svg', false)
            ->assertSeeInOrder(['Sedona Court logo', 'Receipt #:', 'Attending Cashier:'], false);
        $this->get(route('folios.billing', $folio))->assertSee('billing-sedona-logo.png')
            ->assertDontSee('billing-facebook-qr.svg')->assertDontSee('Scan to visit our Facebook page')
            ->assertSee('SCTI-20261015-001')->assertDontSee('<svg', false);
    }

    public function test_receipt_and_statement_show_declared_hours_and_exact_elapsed_time_after_an_overnight_stay(): void
    {
        $this->travelTo('2026-10-14 22:04:00');
        $actor = $this->cashier();
        $folio = $this->checkIn($actor);
        $this->travelTo('2026-10-15 03:14:00');
        $this->checkout($folio);
        $this->travelTo('2026-10-16 12:00:00');

        foreach (['folios.receipt', 'folios.billing'] as $route) {
            $this->get(route($route, $folio))->assertSeeInOrder(['Declared stay:', '3 hours', 'Total time used:', '5h 10m'])
                ->assertSee('260.00')->assertSee('655.00');
        }
    }

    public function test_active_receipt_preview_separates_the_declared_package_from_extensions_and_elapsed_minutes(): void
    {
        $actor = $this->cashier();
        $folio = $this->checkIn($actor);
        $folio->update(['extra_hours' => 1, 'expected_checkout_at' => now()->addHours(4)]);
        $this->travelTo('2026-10-15 12:10:59');

        $this->get(route('folios.receipt', $folio))
            ->assertSeeInOrder(['Declared stay:', '3 hours', 'Added extension:', '+1 hour', 'Total time used:', '0h 10m']);
    }

    public function test_checkout_retries_and_reprints_keep_one_receipt_number(): void
    {
        $actor = $this->cashier();
        $folio = $this->checkIn($actor);
        $data = $this->settlement();
        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasNoErrors();
        $issuedAt = $folio->fresh()->receipt_issued_at;
        $this->travelTo('2026-10-16 01:00:00');

        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasNoErrors();
        $this->post(route('folios.receipt.print', $folio), ['operation_key' => (string) Str::uuid()])->assertSee('SCTI-20261015-001');
        $this->post(route('folios.receipt.print', $folio), ['operation_key' => (string) Str::uuid()])->assertSee('SCTI-20261015-001');

        $this->assertSame('SCTI-20261015-001', $folio->fresh()->receipt_number);
        $this->assertTrue($issuedAt->equalTo($folio->fresh()->receipt_issued_at));
        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertDatabaseHas('document_sequences', ['name' => 'scti:20261015', 'last_number' => 1]);
        $this->assertDatabaseMissing('document_sequences', ['name' => 'scti:20261016']);
    }

    public function test_receipt_date_uses_checkout_at_manila_midnight_instead_of_arrival_date(): void
    {
        config(['app.timezone' => 'UTC']);
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->travelTo(CarbonImmutable::parse('2026-10-15 15:55:00', 'UTC'));
            $actor = $this->cashier();
            $first = $this->checkIn($actor);
            $second = $this->checkIn($actor);
            $this->travelTo(CarbonImmutable::parse('2026-10-15 15:59:59', 'UTC'));
            $this->checkout($second);
            $this->travelTo(CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'));
            $this->checkout($first);

            $this->assertSame('SCTI-20261015-001', $second->fresh()->receipt_number);
            $this->assertSame('SCTI-20261016-001', $first->fresh()->receipt_number);
            $this->assertSame('STAY-20261015-001', $first->fresh()->transaction_id);
            $this->get(route('receipts.index', ['date' => '2026-10-15', 'shift' => 'night']))
                ->assertViewHas('receiptCount', 2)->assertSee('SCTI-20261016-001')->assertSee('SCTI-20261015-001');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_payment_validation_and_rollback_do_not_consume_receipt_numbers(): void
    {
        $actor = $this->cashier();
        $folio = $this->checkIn($actor);
        $data = $this->settlement();
        $this->post(route('checkout.submit', $folio), array_replace($data, ['cash_tendered' => 1]))->assertSessionHasErrors('cash_tendered');
        $this->assertSame(0, DB::table('document_sequences')->where('name', 'like', 'scti:%')->count());
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit storage unavailable'));
        $this->app['router']->getRoutes()->getByName('checkout.submit')->flushController();
        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasNoErrors()->assertInternalServerError();

        $this->assertNull($folio->fresh()->receipt_number);
        $this->assertSame('active', $folio->fresh()->status);
        $this->assertSame(0, DB::table('document_sequences')->where('name', 'like', 'scti:%')->count());
        $this->assertDatabaseCount('payment_entries', 0);
        $this->app->instance(AuditService::class, new AuditService);
        $this->app['router']->getRoutes()->getByName('checkout.submit')->flushController();
        $this->post(route('checkout.submit', $folio), $data)->assertSessionHasNoErrors();
        $this->assertSame('SCTI-20261015-001', $folio->fresh()->receipt_number);
    }

    public function test_cancelled_stays_do_not_consume_receipt_numbers(): void
    {
        $admin = $this->cashier('admin');
        $cancelled = $this->checkIn($admin);
        $this->post(route('folios.cancel', $cancelled), ['operation_key' => (string) Str::uuid(), 'reason' => 'Wrong room selected'])->assertSessionHasNoErrors();
        $next = $this->checkIn($admin);
        $this->checkout($next);

        $this->assertNull($cancelled->fresh()->receipt_number);
        $this->assertSame('SCTI-20261015-001', $next->fresh()->receipt_number);
    }

    public function test_migration_preserves_old_receipts_and_reserves_legacy_numbers_without_numbering_open_or_voided_stays(): void
    {
        $actor = $this->cashier();
        $settled = $this->checkIn($actor);
        $active = $this->checkIn($actor);
        $voided = $this->checkIn($actor);
        $settled->update(['transaction_id' => 'SCTI-20261015-004', 'status' => 'checked_out', 'checked_out_at' => now()]);
        $active->update(['transaction_id' => 'SCTI-20261015-007']);
        $voided->update(['transaction_id' => 'SCTI-20261015-008', 'status' => 'voided', 'checked_out_at' => now()]);
        $migration = require database_path('migrations/2026_10_15_131322_add_checkout_receipt_numbers_to_folios.php');
        $migration->down();

        $migration->up();

        $this->assertSame('SCTI-20261015-004', $settled->fresh()->receipt_number);
        $this->assertNull($active->fresh()->receipt_number);
        $this->assertNull($voided->fresh()->receipt_number);
        $this->checkout($active);
        $this->assertSame('SCTI-20261015-009', $active->fresh()->receipt_number);
        $this->get(route('folios.receipt', $settled))->assertSee('SCTI-20261015-004');
    }

    public function test_receipt_counter_continues_past_999_and_does_not_reuse_a_number_after_a_counter_is_stale(): void
    {
        $actor = $this->cashier();
        $first = $this->checkIn($actor);
        $second = $this->checkIn($actor);
        DB::table('document_sequences')->insert(['name' => 'scti:20261015', 'last_number' => 999]);
        $this->checkout($first);
        DB::table('document_sequences')->where('name', 'scti:20261015')->update(['last_number' => 999]);
        $this->checkout($second);

        $this->assertSame('SCTI-20261015-1000', $first->fresh()->receipt_number);
        $this->assertSame('SCTI-20261015-1001', $second->fresh()->receipt_number);
        $this->get(route('receipts.index'))->assertViewHas('folios', fn ($folios): bool => $folios->modelKeys() === [$first->id, $second->id]);
    }

    public function test_receipt_register_requires_the_same_access_as_check_in_register(): void
    {
        $this->get(route('receipts.index'))->assertRedirect(route('login'));
        $kitchen = User::factory()->create()->assignRole(Role::findOrCreate('kitchen', 'web'));
        $this->actingAs($kitchen)->get(route('receipts.index'))->assertForbidden();
    }

    public function test_cashiers_share_one_checkout_sequence_and_keep_their_own_attribution(): void
    {
        config(['sedona.continuous_shift_enabled' => true]);
        $firstCashier = $this->cashier();
        $secondCashier = $this->cashier();
        $firstStay = $this->checkIn($firstCashier);
        $secondStay = $this->checkIn($secondCashier);

        $this->checkout($secondStay);
        $this->actingAs($firstCashier);
        $this->checkout($firstStay);

        $this->assertSame('SCTI-20261015-001', $secondStay->fresh()->receipt_number);
        $this->assertSame('SCTI-20261015-002', $firstStay->fresh()->receipt_number);
        $this->assertSame($firstCashier->id, $firstStay->fresh()->checked_out_by);
        $this->assertSame($secondCashier->id, $secondStay->fresh()->checked_out_by);
        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_receipt_register_keeps_whole_day_totals_and_numeric_order_when_paginated(): void
    {
        $actor = $this->cashier();
        for ($number = 1; $number <= 31; $number++) {
            $room = Room::create(['number' => (string) $number, 'floor' => 1, 'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'available']);
            Folio::create(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Settled guest', 'headcount' => 2])->id,
                'user_id' => $actor->id, 'transaction_id' => 'STAY-20261014-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'status' => 'checked_out', 'rate_tier' => '3h', 'checked_in_at' => now()->subHours(3), 'expected_checkout_at' => now(), 'checked_out_at' => now(),
                'receipt_number' => 'SCTI-20261015-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'receipt_issued_at' => now()]);
        }

        $response = $this->actingAs($actor)->get(route('receipts.index', ['date' => '2026-10-15', 'page' => 2]));

        $response->assertViewHas('receiptCount', 31)->assertViewHas('guestCount', 62)
            ->assertSee('SCTI-20261015-031')
            ->assertViewHas('folios', fn ($folios): bool => $folios->pluck('receipt_number')->all() === ['SCTI-20261015-031']);
        $this->assertCount(1, $response->viewData('folios')->items());
        $this->assertStringContainsString('date=2026-10-15', $response->viewData('folios')->url(1));
    }

    private function cashier(string $role = 'cashier'): User
    {
        $actor = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'record_payments', 'manage_shifts'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        if (! Shift::whereNull('closed_at')->exists()) {
            Shift::create(['opened_by' => $actor->id, 'shift_type' => 'day', 'shift_date' => today(), 'opened_at' => now(), 'opening_float' => 100]);
        }

        return $actor;
    }

    private function checkIn(User $actor): Folio
    {
        $room = Room::create(['number' => (string) (Room::count() + 1), 'floor' => 1,
            'name' => 'Classic', 'type' => 'Classic Room', 'status' => 'available', 'base_rate_3h' => 395]);
        $this->actingAs($actor)->post(route('checkin.store'), ['operation_key' => (string) Str::uuid(),
            'room_id' => $room->id, 'headcount' => 2, 'rate_tier' => '3h'])->assertSessionHasNoErrors();

        return Folio::where('room_id', $room->id)->firstOrFail();
    }

    private function checkout(Folio $folio): void
    {
        $this->post(route('checkout.submit', $folio), $this->settlement())->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    }

    /** @return array{operation_key: string, payment_method: string, cash_tendered: int} */
    private function settlement(): array
    {
        return ['operation_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => 1000];
    }
}
