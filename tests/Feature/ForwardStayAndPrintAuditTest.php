<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ForwardStayAndPrintAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-25 19:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_open_time_at_eleven_hours_can_convert_after_printing_with_the_twelve_hour_discount(): void
    {
        $folio = $this->stay(['stay_mode' => 'open', 'discount_type' => 'DC']);
        $this->actingAs($this->actor())->post(route('folios.billing.print', $folio), $this->key())->assertOk();
        $dashboard = $this->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => isset($options[$folio->id]['conversions']['12h']));
        $this->savePreview('dashboard', $dashboard->getContent());

        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Guest requested twelve hours'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '12h', 'stay_mode' => 'fixed', 'room_charge' => 1195, 'discount_amount' => 55, 'expected_checkout_at' => '2026-10-25 20:00:00']);
    }

    #[TestWith(['pau@sedonapms.com'])]
    #[TestWith(['admin1@sedonapms.com'])]
    public function test_conversion_cannot_shorten_previously_granted_hours_for_cashier_or_admin(string $email): void
    {
        $folio = $this->stay(['extra_hours' => 10, 'expected_checkout_at' => '2026-10-25 21:00:00']);
        $before = $folio->fresh()->getAttributes();
        $this->actingAs($this->actor($email))->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => ! isset($options[$folio->id]['conversions']['12h']) && isset($options[$folio->id]['conversions']['24h']));

        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Attempt to shorten'])->assertSessionHasErrors('rate_tier');

        $this->assertSame($before, $folio->fresh()->getAttributes());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'folio.package_converted']);
    }

    public function test_conversion_can_replace_extensions_when_the_existing_checkout_is_preserved(): void
    {
        $folio = $this->stay(['extra_hours' => 9, 'expected_checkout_at' => '2026-10-25 20:00:00']);
        $this->actingAs($this->actor())->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Replace hourly charges with package'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'rate_tier' => '12h', 'extra_hours' => 0, 'room_charge' => 1195, 'expected_checkout_at' => '2026-10-25 20:00:00']);
    }

    public function test_elapsed_open_time_package_is_hidden_and_rejected_on_direct_post(): void
    {
        $folio = $this->stay(['stay_mode' => 'open', 'checked_in_at' => '2026-10-25 07:00:00', 'expected_checkout_at' => '2026-10-25 10:00:00']);
        $this->actingAs($this->actor())->get(route('dashboard'))->assertViewHas('folioExtensionOptions', fn (array $options): bool => ! isset($options[$folio->id]['conversions']['12h']) && isset($options[$folio->id]['conversions']['24h']));
        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Already elapsed'])->assertSessionHasErrors('rate_tier');
        $this->assertSame('open', $folio->fresh()->stay_mode);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_extensions_cannot_remove_hours(int $hours): void
    {
        $folio = $this->stay();
        $before = $folio->fresh()->getAttributes();
        $this->actingAs($this->actor())->post(route('folios.extend', $folio), $this->key() + ['hours' => $hours])->assertSessionHasErrors('hours');
        $this->assertSame($before, $folio->fresh()->getAttributes());
    }

    public function test_print_snapshots_and_retry_remain_unchanged_after_conversion_and_reprint(): void
    {
        $folio = $this->stay(['stay_mode' => 'open', 'discount_type' => 'DC']);
        $firstKey = $this->key();
        $this->actingAs($this->actor())->post(route('folios.billing.print', $folio), $firstKey)->assertOk();
        $first = DB::table('audit_logs')->where('event', 'billing.print_requested')->sole();
        $snapshot = json_decode($first->new_values, true)['snapshot'];
        $this->assertSame('6', $snapshot['room_number']);
        $this->assertSame('3h', $snapshot['folio']['rate_tier']);
        $this->assertSame(395, $snapshot['billing']['roomRate']);
        $this->post(route('folios.convert', $folio), $this->key() + ['rate_tier' => '12h', 'reason' => 'Package requested'])->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $this->post(route('folios.billing.print', $folio), $this->key())->assertOk()->assertViewHas('receiptCopy', 2)->assertViewHas('roomRate', 1195.0);

        $this->post(route('folios.billing.print', $folio), $firstKey)->assertOk()->assertViewHas('receiptCopy', 1)->assertViewHas('roomRate', 395)
            ->assertViewHas('folio', fn (Folio $printed): bool => $printed->rate_tier === '3h' && $printed->isOpenTime());

        $this->assertSame($first->new_values, DB::table('audit_logs')->where('id', $first->id)->value('new_values'));
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'billing.print_requested')->count());
        $this->assertSame('12h', $folio->fresh()->rate_tier);
    }

    public function test_admin_print_summary_separates_stays_and_cashier_only_sees_their_own_events(): void
    {
        $folio = $this->stay(['stay_mode' => 'open']);
        $otherStay = $this->stay(['checked_in_at' => '2026-10-24 08:00:00', 'expected_checkout_at' => '2026-10-24 11:00:00', 'checked_out_at' => '2026-10-24 10:00:00', 'status' => 'checked_out', 'gross_total' => 395, 'net_total' => 395]);
        $this->actingAs($this->actor())->post(route('folios.billing.print', $folio), $this->key())->assertOk();
        $this->post(route('folios.billing.print', $folio), $this->key())->assertOk();
        $this->actingAs($this->actor('admin1@sedonapms.com'))->post(route('folios.billing.print', $otherStay), $this->key())->assertOk();

        $response = $this->get(route('transactions.audit', ['room_id' => $folio->room_id]))->assertOk()->assertSee('Statement / Billing print summary by room and stay');
        $summaries = $response->viewData('printSummaries');
        $this->assertSame(2, $summaries->total());
        $this->assertSame(2, $summaries->firstWhere('folio_id', $folio->id)->print_count);
        $this->assertSame(1, $summaries->firstWhere('folio_id', $otherStay->id)->print_count);
        $this->get(route('transactions.audit', ['folio_id' => $folio->id]))->assertViewHas('events', fn ($events): bool => $events->total() === 2);
        $this->actingAs($this->actor())->get(route('transactions.audit'))->assertOk()->assertViewHas('printSummaries', null)
            ->assertDontSee('Statement / Billing print summary by room and stay')->assertDontSee($otherStay->transaction_id);
        $this->savePreview('audit', $response->getContent());
    }

    public function test_guest_printouts_omit_the_guest_name_and_keep_essential_room_and_charge_details(): void
    {
        $folio = $this->stay(['status' => 'checked_out', 'checked_out_at' => '2026-10-25 19:00:00', 'rate_tier' => '12h', 'expected_checkout_at' => '2026-10-25 20:00:00', 'room_charge' => 1195, 'net_total' => 1605, 'gross_total' => 1605, 'payment_method' => 'cash', 'cash_tendered' => 1605,
            'pos_items' => [['name' => 'Calamares', 'qty' => 1, 'price' => 180, 'subtotal' => 180], ['name' => 'Lechon Kawali', 'qty' => 1, 'price' => 230, 'subtotal' => 230]], 'pos_total' => 410]);
        foreach (['receipt' => 'folios.receipt', 'billing' => 'folios.billing', 'gate' => 'folios.gate_pass', 'orders' => 'folios.orderslip'] as $label => $route) {
            $response = $this->actingAs($this->actor())->get(route($route, $folio))->assertOk()->assertDontSee('Confidential Guest')->assertDontSee('Guest Name:')->assertDontSee('shape-rendering="crispEdges"', false);
            $this->savePreview($label, $response->getContent());
            if ($label === 'orders') {
                $response->assertSee('Calamares')->assertSee('Lechon Kawali')->assertSee('410.00')->assertSee('ROOM 6');
            }
            if ($label === 'gate') {
                $response->assertSee('CLEARED FOR EXIT')->assertSee('ROOM 6');
            }
        }
    }

    private function actor(string $email = 'pau@sedonapms.com'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function stay(array $overrides = []): Folio
    {
        $room = Room::where('number', '6')->firstOrFail();
        $room->update(['status' => 'occupied']);

        return Folio::create($overrides + ['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Confidential Guest', 'headcount' => 2])->id,
            'user_id' => $this->actor()->id, 'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => '3h', 'stay_mode' => 'fixed', 'extra_hours' => 0,
            'status' => 'active', 'room_charge' => 395, 'checked_in_at' => '2026-10-25 08:00:00', 'expected_checkout_at' => '2026-10-25 11:00:00']);
    }

    private function key(): array
    {
        return ['operation_key' => (string) Str::uuid()];
    }

    private function savePreview(string $name, string $html): void
    {
        if ($directory = getenv('SEDONA_PRINT_QA_DIR')) {
            $property = getenv('SEDONA_PRINT_QA_PROPERTY') ?: 'court';
            $html = preg_replace('/https?:\/\/[^\/\"]+\/billing-sedona-logo\.png/', '/billing-sedona-logo.png', $html);
            if (in_array($name, ['audit', 'dashboard'], true)) {
                $html = str_replace('</head>', '<link rel="stylesheet" href="/'.$property.'-app.css"></head>', $html);
            }
            file_put_contents($directory.'/'.$property.'-'.$name.'.html', $html);
        }
    }
}
