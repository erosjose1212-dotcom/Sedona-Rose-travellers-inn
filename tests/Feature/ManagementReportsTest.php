<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\ManagementReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagementReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00', 'Asia/Manila'));
    }

    #[TestWith(['admin', 'shift_forms'])]
    #[TestWith(['owner', 'transfer'])]
    #[TestWith(['admin', 'financial_audit'])]
    #[TestWith(['owner', 'ledger'])]
    public function test_managers_can_preview_and_download_each_report(string $role, string $type): void
    {
        $actor = $this->actor($role);
        $this->stay($actor, '2026-10-26 07:00', '2026-10-26 10:00');
        $this->stay($actor, '2026-10-26 18:00', null);
        $params = ['range' => 'custom', 'start_date' => '2026-10-26', 'end_date' => '2026-10-27', 'report' => $type];
        $response = $this->actingAs($actor)->get(route('admin.accounting.reports', $params))->assertOk()
            ->assertSee('Download Excel')->assertSee('2026-10-26')->assertSee('2026-10-27');
        $download = $this->get(route('admin.accounting.reports', $params + ['format' => 'xlsx']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = tempnam(sys_get_temp_dir(), 'sedona-report-');
        try {
            file_put_contents($file, $download->streamedContent());
            $book = IOFactory::load($file);
            $this->assertSame($type === 'financial_audit' ? 6 : ($type === 'shift_forms' ? 3 : 1), $book->getSheetCount());
            $this->assertSame(config('sedona.hotel_name'), $book->getSheet(0)->getCell('A1')->getValue());
            if ($type === 'ledger') {
                $this->assertSame('n', $book->getSheet(0)->getCell('B7')->getDataType());
            }
            if ($type === 'financial_audit') {
                $this->assertSame('Profit & Loss Summary', $book->getSheet(0)->getTitle());
                $this->assertNotContains('TOTAL', array_column($book->getSheet(0)->toArray(), 0));
                $this->assertSame(395.0, $book->getSheetByName('Guest Bills & POS Sales')->getCell('L7')->getValue());
                $this->assertSame(395.0, $book->getSheetByName('Guest Bills & POS Sales')->getCell('L8')->getCalculatedValue());
                $this->assertNull($book->getSheetByName('Shift Collections Summary')->getCell('F12')->getValue());
            }
            if ($type === 'transfer') {
                $this->assertNotContains('TOTAL', array_column($book->getSheet(0)->toArray(), 0));
                $this->assertNull($book->getSheet(0)->getCell('B7')->getValue());
            }
            if ($output = getenv('SEDONA_REPORT_QA_OUTPUT')) {
                file_put_contents($output.'/'.$type.'.html', $response->getContent());
                copy($file, $output.'/'.$type.'.xlsx');
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    #[TestWith(['cashier'])]
    #[TestWith(['front_desk'])]
    #[TestWith(['kitchen'])]
    #[TestWith(['customer_display'])]
    public function test_other_roles_cannot_preview_or_download_even_with_forged_filters(string $role): void
    {
        $actor = $this->actor($role);
        foreach (array_keys(ManagementReportService::REPORTS) as $type) {
            foreach (['preview', 'xlsx'] as $format) {
                $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => $type, 'format' => $format, 'property_access' => 1]))->assertForbidden();
            }
        }
    }

    public function test_signed_out_users_are_redirected_before_accessing_exports(): void
    {
        $this->get(route('admin.accounting.reports', ['format' => 'xlsx']))->assertRedirect(route('login'));
    }

    #[TestWith(['Asia/Manila'])]
    #[TestWith(['UTC'])]
    public function test_multi_date_day_filter_excludes_nights_and_end_boundary(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        $actor = $this->actor('admin');
        $first = $this->stay($actor, '2026-10-26 03:30', '2026-10-26 06:30');
        $second = $this->stay($actor, '2026-10-27 15:29', '2026-10-27 18:29:59');
        $night = $this->stay($actor, '2026-10-26 15:30', '2026-10-26 18:30');
        $end = $this->stay($actor, '2026-10-27 15:30', '2026-10-27 18:30');
        $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'ledger', 'range' => 'custom', 'start_date' => '2026-10-26', 'end_date' => '2026-10-27', 'shift' => 'day']))->assertOk()
            ->assertSee($first->receipt_number)->assertSee($second->receipt_number)->assertDontSee($night->receipt_number)->assertDontSee($end->receipt_number)
            ->assertViewHas('report', fn (array $report): bool => count($report['sheets'][0]['rows']) === 2);
    }

    public function test_night_range_includes_next_morning_but_not_the_next_day_shift(): void
    {
        $actor = $this->actor('owner');
        $night = $this->stay($actor, '2026-10-26 20:30', '2026-10-27 06:29:59');
        $day = $this->stay($actor, '2026-10-27 03:30', '2026-10-27 06:30');
        $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'ledger', 'report_date' => '2026-10-26', 'shift' => 'night']))->assertOk()
            ->assertSee($night->receipt_number)->assertDontSee($day->receipt_number);
    }

    public function test_financial_workbook_reconciles_deposits_refunds_and_legacy_tenders_without_duplicates(): void
    {
        $actor = $this->actor('owner');
        $stay = $this->stay($actor, '2026-10-26 07:00', '2026-10-26 10:00', ['payment_method' => 'split', 'cash_tendered' => 295, 'gcash_amount' => 100]);
        PaymentEntry::create(['folio_id' => $stay->id, 'user_id' => $actor->id, 'kind' => 'settlement', 'cash_amount' => 295, 'gcash_amount' => 100, 'gcash_reference' => 'GCASH-001', 'posted_at' => $this->at('2026-10-26 10:00')]);
        PaymentEntry::create(['folio_id' => $stay->id, 'user_id' => $actor->id, 'kind' => 'deposit', 'cash_amount' => 0, 'gcash_amount' => 500, 'gcash_reference' => 'DEPOSIT-001', 'posted_at' => $this->at('2026-10-26 08:00')]);
        PaymentEntry::create(['folio_id' => $stay->id, 'user_id' => $actor->id, 'kind' => 'deposit_refund', 'cash_amount' => 0, 'gcash_amount' => -500, 'gcash_reference' => 'REFUND-001', 'posted_at' => $this->at('2026-10-26 11:00')]);
        $this->stay($actor, '2026-10-26 11:00', '2026-10-26 14:00');
        $old = $this->stay($actor, '2026-09-12 07:00', '2026-09-12 10:00');
        $response = $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'financial_audit', 'report_date' => '2026-10-26']))->assertOk()->assertDontSee($old->receipt_number);
        $report = $response->viewData('report');
        $metrics = collect($report['sheets'][0]['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame(790.0, $metrics['Gross revenue']);
        $this->assertSame(690.0, $metrics['Net cash collections']);
        $this->assertSame(100.0, $metrics['Net GCash collections']);
        $this->assertCount(3, $report['sheets'][4]['rows']);
        $this->assertSame(100.0, array_sum(array_column($report['sheets'][4]['rows'], 3)));
        $this->assertSame(100.0, array_sum(array_column($report['sheets'][1]['rows'], 13)));
    }

    public function test_carryover_is_reconstructed_at_each_boundary_and_does_not_issue_receipts(): void
    {
        $actor = $this->actor('admin');
        $stay = $this->stay($actor, '2026-10-26 15:00', '2026-10-27 02:00');
        $active = $this->stay($actor, '2026-10-26 16:00', null);
        $response = $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'transfer', 'report_date' => '2026-10-26', 'shift' => 'day']))->assertOk();
        $rows = $response->viewData('report')['sheets'][0]['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-26 Day', $rows[0][18]);
        $this->assertNull($rows[1][1]);
        $this->assertNull($active->fresh()->receipt_number);
        $this->assertNull($rows[0][1]);
        $this->assertSame(0, $rows[0][17]);
    }

    public function test_calendar_export_matches_existing_pnl_filters_and_expense_date_basis(): void
    {
        $actor = $this->actor('admin');
        $early = $this->stay($actor, '2026-10-25 22:00', '2026-10-26 01:00');
        $next = $this->stay($actor, '2026-10-26 22:00', '2026-10-27 01:00');
        $expense = Expense::create(['user_id' => $actor->id, 'expense_date' => '2026-10-26', 'voucher_number' => 'TEST-VOUCHER', 'description' => 'Paper', 'amount' => 40, 'category' => 'administrative', 'status' => 'approved', 'payment_source' => 'cash_drawer']);
        $expense->forceFill(['created_at' => $this->at('2026-10-25 20:00')])->save();
        $params = ['range' => 'custom', 'start_date' => '2026-10-26', 'end_date' => '2026-10-26'];
        $pnl = $this->actingAs($actor)->get(route('admin.accounting.pnl', $params))->assertOk();
        $report = $this->get(route('admin.accounting.reports', $params + ['report' => 'financial_audit', 'calendar' => 1]))->assertOk()->assertSee($early->receipt_number)->assertDontSee($next->receipt_number)->viewData('report');
        $metrics = collect($report['sheets'][0]['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame($pnl->viewData('grossRevenue'), $metrics['Gross revenue']);
        $this->assertSame(40.0, $metrics['Operational expenses']);
        $this->assertSame($pnl->viewData('netOperatingProfit'), $metrics['Net operating profit']);
    }

    public function test_excel_keeps_formula_like_text_and_leading_zero_ids_as_text(): void
    {
        $actor = $this->actor('owner');
        $stay = $this->stay($actor, '2026-10-26 07:00', '2026-10-26 10:00', ['discount_id_ref' => '00092']);
        $stay->guest->update(['name' => '=HYPERLINK("https://example.invalid","test")']);
        $download = $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'ledger', 'report_date' => '2026-10-26', 'format' => 'xlsx']))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'sedona-report-');
        try {
            file_put_contents($file, $download->streamedContent());
            $book = IOFactory::load($file);
            $this->assertSame('s', $book->getActiveSheet()->getCell('F7')->getDataType());
            $this->assertSame('00092', $book->getActiveSheet()->getCell('O7')->getValue());
            $this->assertSame('s', $book->getActiveSheet()->getCell('O7')->getDataType());
            $this->assertSame('@', $book->getActiveSheet()->getStyle('O7')->getNumberFormat()->getFormatCode());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    #[TestWith([['range' => 'custom', 'start_date' => '2026-10-27', 'end_date' => '2026-10-26'], 'end_date'])]
    #[TestWith([['range' => 'custom', 'start_date' => '2025-09-01', 'end_date' => '2026-10-26'], 'end_date'])]
    #[TestWith([['range' => 'custom'], 'start_date'])]
    #[TestWith([['report' => '../../secret'], 'report'])]
    #[TestWith([['shift' => 'random'], 'shift'])]
    public function test_invalid_and_unbounded_exports_are_rejected(array $params, string $error): void
    {
        $this->actingAs($this->actor('admin'))->get(route('admin.accounting.reports', $params))->assertRedirect()->assertSessionHasErrors($error);
    }

    public function test_cash_counts_use_saved_denominations_and_do_not_invent_counts_for_open_drawers(): void
    {
        $actor = $this->actor('admin');
        Shift::create(['opened_by' => $actor->id, 'closed_by' => $actor->id, 'shift_date' => '2026-10-26', 'shift_type' => 'day', 'opened_at' => $this->at('2026-10-26 06:30'), 'closed_at' => $this->at('2026-10-26 18:30'), 'denomination_count' => ['1000' => 2, 'coins' => 12.50], 'expected_cash' => 2000, 'cash_total' => 2012.50, 'cash_variance' => 12.50]);
        Shift::create(['opened_by' => $actor->id, 'shift_date' => '2026-10-26', 'shift_type' => 'night', 'opened_at' => $this->at('2026-10-26 18:30')]);
        $report = $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'financial_audit', 'report_date' => '2026-10-26']))->assertOk()->viewData('report');
        $rows = $report['sheets'][5]['rows'];
        $this->assertCount(7, $rows);
        $this->assertSame(2000.0, $rows[0][6]);
        $this->assertSame(12.50, $rows[6][6]);
        $this->assertNull($rows[1][5]);
    }

    #[TestWith(['Asia/Manila'])]
    #[TestWith(['UTC'])]
    public function test_finance_day_and_export_use_the_confirmed_six_thirty_boundary(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        $actor = $this->actor('admin');
        $service = app(BusinessDayService::class);
        $this->assertSame('2026-10-25', $service->date($this->at('2026-10-26 06:29:59')));
        $this->assertSame('2026-10-26', $service->date($this->at('2026-10-26 06:30:00')));
        [$start, $end] = $service->bounds('2026-10-26');
        $this->assertSame('2026-10-26 06:30:00', $start->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 06:30:00', $end->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $before = $this->stay($actor, '2026-10-26 03:29', '2026-10-26 06:29:59');
        $inside = $this->stay($actor, '2026-10-26 03:30', '2026-10-26 06:30:00');
        $after = $this->stay($actor, '2026-10-27 03:30', '2026-10-27 06:30:00');
        $expense = Expense::create(['user_id' => $actor->id, 'expense_date' => '2026-10-26', 'voucher_number' => 'FINANCE-EXPENSE', 'description' => 'Paper', 'amount' => 40, 'category' => 'administrative', 'status' => 'approved', 'payment_source' => 'cash_drawer']);
        $expense->forceFill(['created_at' => $this->at('2026-10-25 20:00')])->save();
        $report = $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'financial_audit', 'report_date' => '2026-10-26', 'finance_day' => 1]))->assertOk()
            ->assertSee($inside->receipt_number)->assertDontSee($before->receipt_number)->assertDontSee($after->receipt_number)->viewData('report');
        $metrics = collect($report['sheets'][0]['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame(40.0, $metrics['Operational expenses']);
    }

    public function test_department_and_shift_totals_include_room_addons_and_walkin_sales(): void
    {
        $actor = $this->actor('owner');
        PosItem::create(['name' => 'Meal', 'category' => 'Breakfast', 'price' => 150]);
        $cola = PosItem::create(['name' => 'Cola', 'category' => 'Drinks', 'price' => 60]);
        $this->stay($actor, '2026-10-26 07:00', '2026-10-26 10:00', ['extra_hours' => 2, 'overtime_hours' => 1, 'surcharge_total' => 450, 'pos_total' => 210, 'gross_total' => 1055, 'discount_amount' => 40, 'discount_type' => 'DC', 'discount_id_ref' => '00092', 'net_total' => 1015, 'cash_tendered' => 1015,
            'pos_items' => [['name' => 'Meal', 'qty' => 1, 'subtotal' => 150], ['name' => 'Cola', 'qty' => 1, 'subtotal' => 60]]]);
        $order = Order::create(['user_id' => $actor->id, 'transaction_id' => 'POS-TEST', 'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 100, 'payment_method' => 'cash', 'cash_tendered' => 100]);
        $order->items()->create(['pos_item_id' => $cola->id, 'item_name' => 'Cola', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        $order->forceFill(['created_at' => $this->at('2026-10-26 11:00')])->save();
        $report = $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'financial_audit', 'report_date' => '2026-10-26']))->assertOk()->viewData('report');
        $metrics = collect($report['sheets'][0]['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame(1155.0, $metrics['Gross revenue']);
        $this->assertSame(785.0, $metrics['Room gross revenue']);
        $this->assertSame(150.0, $metrics['Kitchen gross revenue']);
        $this->assertSame(160.0, $metrics['Drinks gross revenue']);
        $this->assertSame(60.0, $metrics['Extras gross revenue']);
        $this->assertSame(1115.0, $metrics['Net cash collections']);
        $this->assertSame(160.0, $report['sheets'][1]['rows'][0][8]);
        $this->get(route('admin.accounting.reports', ['report' => 'shift_forms', 'report_date' => '2026-10-26']))->assertOk()->assertSee('00092');
    }

    #[TestWith(['this_week', '2026-10-26', '2026-11-01'])]
    #[TestWith(['this_month', '2026-10-01', '2026-10-31'])]
    #[TestWith(['last_month', '2026-09-01', '2026-09-30'])]
    public function test_existing_date_presets_are_available_in_exports(string $range, string $first, string $last): void
    {
        $response = $this->actingAs($this->actor('admin'))->get(route('admin.accounting.reports', ['report' => 'ledger', 'range' => $range]))->assertOk();
        $this->assertSame($first, $response->viewData('period')['start_date']);
        $this->assertSame($last, $response->viewData('period')['end_date']);
    }

    public function test_full_year_date_range_is_supported_without_truncating_to_a_month(): void
    {
        $actor = $this->actor('admin');
        $stay = $this->stay($actor, '2026-10-26 07:00', '2026-10-26 10:00');
        $this->actingAs($actor)->get(route('admin.accounting.reports', ['report' => 'ledger', 'range' => 'custom', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']))->assertOk()->assertSee($stay->receipt_number);
    }

    #[TestWith(['2026-10-26 06:29:59', 'night'])]
    #[TestWith(['2026-10-26 06:30:00', 'day'])]
    #[TestWith(['2026-10-26 18:29:59', 'day'])]
    #[TestWith(['2026-10-26 18:30:00', 'night'])]
    public function test_drawer_shift_labels_follow_the_same_manila_boundaries(string $at, string $expected): void
    {
        $time = Carbon::parse($at, 'Asia/Manila')->setTimezone('UTC');
        $this->assertSame($expected, Shift::typeForTime($time));
        $this->assertSame('UTC', $time->timezoneName);
    }

    public function test_realistic_sample_exports_are_generated_by_the_actual_endpoints(): void
    {
        $rose = str_contains(strtolower(config('sedona.hotel_name')), 'rose');
        config(['sedona.property_code' => $rose ? 'rose' : 'court']);
        $this->seed(DatabaseSeeder::class);
        config(['sedona.hotel_name' => config('sedona.hotel_name').' — SAMPLE DATA']);
        $manager = $this->actor('admin');
        $cashier = $this->actor('cashier');
        $cashier->update(['name' => 'Sample Cashier (TEST DATA)']);
        $drawer = Shift::create(['opened_by' => $cashier->id, 'closed_by' => $cashier->id, 'shift_type' => 'day', 'shift_date' => '2026-10-26',
            'opened_at' => $this->at('2026-10-26 06:30'), 'closed_at' => $this->at('2026-10-26 18:30'), 'opening_float' => 1000,
            'expected_cash' => 1420, 'cash_total' => 1420, 'cash_variance' => 0, 'gcash_total' => 1295,
            'denomination_count' => ['1000' => 1, '500' => 0, '200' => 2, '100' => 0, '50' => 0, '20' => 1, 'coins' => 0]]);
        $meal = PosItem::where('name', 'Bangsilog')->firstOrFail();
        $drink = PosItem::where('name', 'Coke Regular (320ml can)')->firstOrFail();
        $makeStay = function (string $roomNumber, string $arrival, ?string $departure, array $values) use ($cashier): Folio {
            $room = Room::where('number', $roomNumber)->firstOrFail();

            return Folio::create(array_merge(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Sample Guest Room '.$roomNumber.' (TEST DATA)', 'headcount' => 2])->id,
                'user_id' => $cashier->id, 'checked_out_by' => $departure ? $cashier->id : null, 'transaction_id' => 'STAY-20261026-'.str_pad($roomNumber, 3, '0', STR_PAD_LEFT),
                'status' => $departure ? 'checked_out' : 'active', 'checked_in_at' => $this->at($arrival), 'checked_out_at' => $departure ? $this->at($departure) : null,
                'rate_tier' => '3h', 'expected_checkout_at' => $this->at($arrival)->addHours(3), 'room_charge' => 395, 'gross_total' => 395, 'net_total' => 395], $values));
        };
        $premium = $makeStay('20', '2026-10-25 20:30', '2026-10-26 08:30', ['receipt_number' => 'SCTI-20261026-001', 'receipt_issued_at' => $this->at('2026-10-26 08:30'),
            'rate_tier' => '12h', 'expected_checkout_at' => $this->at('2026-10-26 08:30'), 'room_charge' => 1295, 'gross_total' => 1295, 'discount_type' => 'SENIOR', 'discount_amount' => 215,
            'discount_id_ref' => 'SC-SAMPLE-002', 'net_total' => 1080, 'payment_method' => 'gcash', 'gcash_amount' => 1080, 'gcash_reference' => 'GCASH-SAMPLE-001']);
        $classic = $makeStay('8', '2026-10-26 07:00', '2026-10-26 10:10', ['receipt_number' => 'SCTI-20261026-002', 'receipt_issued_at' => $this->at('2026-10-26 10:10'),
            'pos_items' => [['name' => $meal->name, 'qty' => 1, 'price' => 150, 'subtotal' => 150], ['name' => $drink->name, 'qty' => 1, 'price' => 60, 'subtotal' => 60]],
            'pos_total' => 210, 'gross_total' => 605, 'discount_type' => 'DC', 'discount_amount' => 40, 'discount_id_ref' => '00092', 'net_total' => 465,
            'security_deposit' => 100, 'deposit_status' => 'applied', 'deposit_payment_method' => 'cash', 'deposit_collected_at' => $this->at('2026-10-26 07:00'),
            'payment_method' => 'split', 'cash_tendered' => 250, 'gcash_amount' => 215, 'gcash_reference' => 'GCASH-SAMPLE-002']);
        $makeStay('6', '2026-10-26 17:00', null, ['stay_mode' => 'open']);
        $makeStay('7', '2026-10-27 05:00', null, []);
        foreach ([[$premium, 'settlement', 0, 1080, '2026-10-26 08:30', 'GCASH-SAMPLE-001'], [$classic, 'deposit', 100, 0, '2026-10-26 07:00', null], [$classic, 'settlement', 250, 215, '2026-10-26 10:10', 'GCASH-SAMPLE-002']] as [$stay, $kind, $cash, $gcash, $at, $reference]) {
            PaymentEntry::create(['folio_id' => $stay->id, 'shift_id' => $drawer->id, 'user_id' => $cashier->id, 'kind' => $kind, 'cash_amount' => $cash, 'gcash_amount' => $gcash, 'gcash_reference' => $reference,
                'posted_at' => $this->at($at), 'business_date' => '2026-10-26']);
        }
        $order = Order::create(['user_id' => $cashier->id, 'shift_id' => $drawer->id, 'transaction_id' => 'POS-SAMPLE-001', 'type' => 'walkin_pos', 'status' => 'delivered', 'total' => 120, 'payment_method' => 'cash', 'cash_tendered' => 120]);
        $order->items()->create(['pos_item_id' => $drink->id, 'item_name' => $drink->name, 'quantity' => 2, 'unit_price' => 60, 'subtotal' => 120]);
        $order->forceFill(['created_at' => $this->at('2026-10-26 12:00')])->save();
        PaymentEntry::create(['order_id' => $order->id, 'shift_id' => $drawer->id, 'user_id' => $cashier->id, 'kind' => 'pos_sale', 'cash_amount' => 120, 'gcash_amount' => 0, 'posted_at' => $this->at('2026-10-26 12:00'), 'business_date' => '2026-10-26']);
        $expense = Expense::create(['user_id' => $cashier->id, 'shift_id' => $drawer->id, 'expense_date' => '2026-10-26', 'voucher_number' => 'EXP-SAMPLE-001', 'description' => 'Sample kitchen supplies (TEST DATA)', 'category' => 'kitchen_inventory', 'amount' => 50, 'status' => 'approved', 'payment_source' => 'cash_drawer']);
        $expense->forceFill(['created_at' => $this->at('2026-10-26 12:30')])->save();
        $this->actingAs($manager);
        $params = ['range' => 'custom', 'start_date' => '2026-10-26', 'end_date' => '2026-10-27', 'shift' => 'all'];
        foreach (array_keys(ManagementReportService::REPORTS) as $type) {
            $preview = $this->get(route('admin.accounting.reports', $params + ['report' => $type]))->assertOk();
            if ($type === 'financial_audit') {
                $metrics = collect($preview->viewData('report')['sheets'][0]['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
                $this->assertSame(1765.0, $metrics['Net settled revenue']);
                $this->assertSame(1715.0, $metrics['Net operating profit']);
                $this->assertSame(470.0, $metrics['Net cash collections']);
                $this->assertSame(1295.0, $metrics['Net GCash collections']);
                if ($rose) {
                    $this->assertSame(20, $metrics['Guest rooms']);
                }
            }
            $download = $this->get(route('admin.accounting.reports', $params + ['report' => $type, 'format' => 'xlsx']))->assertOk();
            $bytes = $download->streamedContent();
            $this->assertStringStartsWith('PK', $bytes);
            if ($output = getenv('SEDONA_REPORT_SAMPLE_OUTPUT')) {
                preg_match('/filename="?([^";]+)/', $download->headers->get('Content-Disposition'), $matches);
                file_put_contents($output.'/'.basename($matches[1]), $bytes);
                file_put_contents($output.'/'.($rose ? 'rose' : 'court').'-'.$type.'.html', $preview->getContent());
            }
        }
    }

    private function actor(string $role): User
    {
        return User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Manila')->setTimezone(config('app.timezone'));
    }

    /** @param array<string, mixed> $attributes */
    private function stay(User $actor, string $arrival, ?string $departure, array $attributes = []): Folio
    {
        $room = Room::create(['number' => Room::count() + 1, 'floor' => 1, 'name' => 'Test room', 'type' => 'Classic Room', 'status' => $departure ? 'available' : 'occupied', 'base_rate_3h' => 395]);

        return Folio::create(array_merge(['room_id' => $room->id, 'guest_id' => Guest::create(['name' => 'Management report guest', 'headcount' => 2])->id,
            'user_id' => $actor->id, 'checked_out_by' => $departure ? $actor->id : null, 'transaction_id' => 'STAY-'.Str::uuid(), 'receipt_number' => $departure ? 'SCTI-'.Str::uuid() : null,
            'status' => $departure ? 'checked_out' : 'active', 'checked_in_at' => $this->at($arrival), 'expected_checkout_at' => $this->at($arrival)->addHours(3), 'checked_out_at' => $departure ? $this->at($departure) : null,
            'rate_tier' => '3h', 'room_charge' => 395, 'gross_total' => 395, 'net_total' => 395, 'cash_tendered' => 395, 'payment_method' => 'cash'], $attributes));
    }
}
