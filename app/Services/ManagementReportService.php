<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManagementReportService
{
    public const REPORTS = [
        'shift_forms' => 'Cashier Shift Summary',
        'transfer' => 'Occupied Rooms for Next Shift',
        'financial_audit' => 'Profit & Loss / Daily Finance',
        'ledger' => 'Guest Bills & POS Sales',
    ];

    public const DESCRIPTIONS = [
        'shift_forms' => 'Guests checked out, bills, discounts and cashier collections, plus rooms carried into the next shift.',
        'transfer' => 'Rooms still occupied at each shift change, with arrival and expected checkout times.',
        'financial_audit' => 'Sales, expenses, profit, cash and GCash collections, discounts and saved drawer counts.',
        'ledger' => 'Completed guest bills and walk-in POS sales, with receipts, itemized purchases and payment details.',
    ];

    public function __construct(private FinancialSummaryService $financials, private ShiftReportService $shifts) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function period(array $filters): array
    {
        $calendar = (bool) ($filters['calendar'] ?? false);
        $financeDay = (bool) ($filters['finance_day'] ?? false);
        $timezone = $calendar ? config('app.timezone') : 'Asia/Manila';
        $today = $calendar ? CarbonImmutable::now($timezone)->startOfDay() : ($financeDay ? CarbonImmutable::parse(app(BusinessDayService::class)->date(), 'Asia/Manila') : $this->shifts->period()['day']);
        $range = $filters['range'] ?? 'today';
        [$first, $last] = match ($range) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'custom' => [CarbonImmutable::parse($filters['start_date'], $timezone), CarbonImmutable::parse($filters['end_date'], $timezone)],
            default => [CarbonImmutable::parse($filters['report_date'] ?? $today->toDateString(), $timezone), CarbonImmutable::parse($filters['report_date'] ?? $today->toDateString(), $timezone)],
        };
        $shift = $filters['shift'] ?? 'all';
        $windows = [];
        for ($day = $first->startOfDay(); $day->lte($last->startOfDay()); $day = $day->addDay()) {
            if ($financeDay) {
                [$start, $end] = app(BusinessDayService::class)->bounds($day->toDateString());
                $windows[] = ['date' => $day->toDateString(), 'shift' => 'finance day', 'start' => $start, 'end' => $end];

                continue;
            }
            if ($calendar) {
                $windows[] = ['date' => $day->toDateString(), 'shift' => 'calendar', 'start' => $day, 'end' => $day->addDay()];

                continue;
            }
            foreach ($shift === 'all' ? ['day', 'night'] : [$shift] as $part) {
                $windows[] = $this->shifts->period($day->toDateString(), $part);
            }
        }

        return ['range' => $range, 'start_date' => $first->toDateString(), 'end_date' => $last->toDateString(), 'shift' => $shift, 'calendar' => $calendar, 'finance_day' => $financeDay,
            'start' => $windows[0]['start'], 'end' => end($windows)['end'], 'windows' => $windows];
    }

    /** @param array<string, mixed> $period */
    private function within(Builder $query, string $column, array $period): Builder
    {
        if ($period['shift'] === 'all') {
            return $query->where($column, '>=', $period['start']->setTimezone(config('app.timezone')))
                ->where($column, '<', $period['end']->setTimezone(config('app.timezone')));
        }

        return $query->where(function (Builder $query) use ($column, $period): void {
            foreach ($period['windows'] as $window) {
                $query->orWhere(fn (Builder $query): Builder => $query
                    ->where($column, '>=', $window['start']->setTimezone(config('app.timezone')))
                    ->where($column, '<', $window['end']->setTimezone(config('app.timezone'))));
            }
        });
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    public function build(string $type, array $period): array
    {
        $folios = $this->within(Folio::with(['room', 'guest', 'checkedOutBy', 'cashier'])->where('status', 'checked_out'), 'checked_out_at', $period)
            ->orderBy('checked_out_at')->orderBy('id')->get();
        $orders = $this->within(Order::with(['items', 'cashier'])->where('type', 'walkin_pos'), 'created_at', $period)
            ->orderBy('created_at')->orderBy('id')->get();
        $entries = $this->within(PaymentEntry::with(['folio.room', 'folio.guest', 'order', 'user']), 'posted_at', $period)
            ->orderBy('posted_at')->orderBy('id')->get();
        $catalog = PosItem::all(['name', 'category'])->mapWithKeys(fn (PosItem $item): array => [mb_strtolower(trim($item->name)) => $item->category])->all();
        $arrivals = $this->within(Folio::with('guest')->where('status', '!=', 'voided')->whereHas('room', fn (Builder $query): Builder => $query->where('is_staff_quarters', false)), 'checked_in_at', $period)->get();
        $payments = $this->payments($entries, $folios, $orders);
        $journal = $this->journal($folios, $orders);
        $carry = $type === 'ledger' ? collect() : $this->carryovers($period);
        $notes = [
            'Hotel time: Asia/Manila. Day: 6:30 AM–6:30 PM; night: 6:30 PM–next 6:30 AM. Start included, end excluded.',
            'Room bills are recognized at checkout; deposits and refunds appear in collections, not as additional revenue. Historical records use their saved amounts.',
            'Coupon numbers and service-charge amounts are not recorded separately in this system. They are left blank, not inferred.',
        ];
        if ($period['calendar']) {
            $notes[0] = 'Calendar dates in '.config('app.timezone').', matching the selected Executive P&L range. Start included, next midnight excluded. Expenses use expense date. Displayed transaction times use Asia/Manila.';
        }
        if ($period['finance_day']) {
            $notes[0] = 'Accounting days run 6:30 AM–next 6:30 AM in Asia/Manila, matching Daily Finance and both operational shifts. These exports use current saved records, not frozen day-close snapshots.';
        }
        $sheets = [];
        if (in_array($type, ['shift_forms', 'transfer'], true)) {
            if ($type === 'shift_forms') {
                $sheets[] = $this->staySheet('Cashier Shift Summary', $folios, $catalog);
                $sheets[] = $this->cashierCollections($payments, $period);
            }
            $sheets[] = $this->staySheet('Occupied Rooms Next Shift', $carry, $catalog, true);
            $notes[] = 'Transfer rows show guests still in rooms at each selected shift boundary (or now for the current shift). Repeated stays belong to distinct boundaries. Room, expected checkout and bill details use the current saved stay record. Receipt numbers stay blank until checkout; a receipt issued later is not shown in an earlier handover.';
        } elseif ($type === 'ledger') {
            $sheets[] = $journal;
        } else {
            $summary = $this->summary($period);
            $totalRooms = Room::where('is_staff_quarters', false)->count();
            $occupied = Room::where('is_staff_quarters', false)->whereHas('activeFolio')->count();
            $channels = ['room' => 0.0, 'kitchen' => 0.0, 'drinks' => 0.0, 'misc' => 0.0, 'extras' => 0.0];
            foreach ($folios as $folio) {
                foreach ($this->charges($folio, $catalog) as $key => $value) {
                    $channels[$key] += $value;
                }
            }
            foreach ($orders as $order) {
                foreach ($this->orderCharges($order, $catalog) as $key => $value) {
                    $channels[$key] += $value;
                }
            }
            $dashboard = [
                ['Gross revenue', $summary['grossRevenue'], 'Before discounts, losses and returns'],
                ['Discounts', $summary['discounts'], 'Saved checkout discounts'],
                ['Approved losses', $summary['losses'], 'Uncollected stays'],
                ['Sales returns', $summary['salesReturns'], 'Refunded POS sales'],
                ['Net settled revenue', $summary['netRevenue'], 'Gross less discounts, losses and returns'],
                ['Recorded invoice tax', $summary['invoiceTax'], 'Recorded tax, net of returns'],
                ['Operational expenses', $summary['totalExpenses'], $period['calendar'] || $period['finance_day'] ? 'Paid expenses, by expense date' : 'Paid expenses, by recorded time'],
                ['Matched provider fees', $summary['providerFees'], 'Matched GCash / bank fees'],
                ['Net operating profit', $summary['profit'], 'Net revenue less tax, expenses and provider fees'],
                ['Net cash collections', $summary['cashCollections'], 'Includes deposits and refunds'],
                ['Net GCash collections', $summary['gcashCollections'], 'Includes deposits and refunds'],
                ['Deposits received', round($payments->where('kind', 'deposit')->sum(fn (array $row): float => $row['cash'] + $row['gcash']), 2), 'Liability; not additional revenue'],
                ['Deposits refunded', round(-$payments->where('kind', 'deposit_refund')->sum(fn (array $row): float => $row['cash'] + $row['gcash']), 2), 'Returned guest funds'],
                ['Guest rooms', $totalRooms, 'Excludes staff quarters'],
                ['Check-ins', $arrivals->count(), 'Arrivals during selected shifts'],
                ['Guests checked in', (int) $arrivals->sum(fn (Folio $folio): int => (int) $folio->guest?->headcount), 'Recorded guest count'],
                ['Checkouts', $folios->count(), 'Completed stays during selected shifts'],
                ['Live occupancy (%)', $totalRooms ? round($occupied / $totalRooms * 100, 2) : null, 'Current occupied guest rooms / guest room capacity'],
                ['Live occupied rooms', $occupied, 'Current snapshot: '.now()->setTimezone('Asia/Manila')->format('Y-m-d h:i A')],
                ['Average base room bill', $folios->count() ? round($summary['roomRevenue'] / $folios->count(), 2) : null, 'Per completed stay; not a nightly ADR'],
            ];
            foreach ($channels as $key => $value) {
                $dashboard[] = [ucfirst($key).' gross revenue', round($value, 2), 'Before discounts, losses and returns'];
            }
            $sheets[] = $this->sheet('Profit & Loss Summary', ['Metric', 'Value (PHP / count)', 'Basis'], $dashboard, []);
            $remittances = [];
            foreach ($period['windows'] as $window) {
                $in = $arrivals->filter(fn (Folio $folio): bool => $this->contains($folio->checked_in_at, $window));
                $out = $folios->filter(fn (Folio $folio): bool => $this->contains($folio->checked_out_at, $window));
                $money = $payments->filter(fn (array $row): bool => $this->contains($row['at'], $window));
                $amounts = array_fill_keys(array_keys($channels), 0.0);
                foreach ($out as $folio) {
                    foreach ($this->charges($folio, $catalog) as $key => $value) {
                        $amounts[$key] += $value;
                    }
                }
                foreach ($orders->filter(fn (Order $order): bool => $this->contains($order->created_at, $window)) as $order) {
                    foreach ($this->orderCharges($order, $catalog) as $key => $value) {
                        $amounts[$key] += $value;
                    }
                }
                $carried = $carry->where('shift', $window['date'].' '.ucfirst($window['shift']))->count();
                $remittances[] = [$window['date'], ucfirst($window['shift']), $money->pluck('cashier')->unique()->implode(', '), $in->count(), $out->count(), $carried,
                    $amounts['room'], $amounts['kitchen'], $amounts['drinks'], $amounts['misc'], $amounts['extras'], (float) $out->sum('discount_amount'),
                    round($money->sum('cash'), 2), round($money->sum('gcash'), 2)];
            }
            $sheets[] = $this->sheet('Shift Collections Summary', ['Date', 'Shift', 'Cashiers', 'Check-in', 'Out', 'Rooms still occupied', 'Room bill', 'Kitchen', 'Drinks', 'Miscell', 'Extras', 'Discount', 'Cash received (net)', 'GCash received (net)'], $remittances, [3, 4, 6, 7, 8, 9, 10, 11, 12, 13]);
            $notes[] = 'Occupied-room figures are snapshots at each shift boundary. The same stay can appear at several boundaries; these figures are not added together as new guests or new sales.';
            $sheets[] = $journal;
            $expenseRows = $summary['expenses']->map(fn ($expense): array => [$expense->expense_date?->format('Y-m-d'), $expense->voucher_number, $expense->category, $expense->description, (float) $expense->amount, $expense->payment_source, $expense->status])->all();
            $sheets[] = $this->sheet('Expenses', ['Expense date', 'Voucher', 'Category', 'Expense item', 'Amount (PHP)', 'Paid from', 'Review status'], $expenseRows, [4]);
            $matched = DB::table('statement_matches')->whereIn('payment_entry_id', $entries->modelKeys())->pluck('payment_entry_id')->all();
            $digital = $payments->filter(fn (array $row): bool => $row['gcash'] != 0)->map(fn (array $row): array => [
                $this->time($row['at']), $this->shiftLabel($row['at']), $row['gcash_reference'], $row['gcash'], $row['room'], $row['reference'], $row['kind'], $row['cashier'],
                $row['id'] && in_array($row['id'], $matched, true) ? 'Matched to provider statement' : 'Provider verification pending',
            ])->values()->all();
            $sheets[] = $this->sheet('GCash Collections', ['Posted', 'Shift', 'GCash reference', 'Amount (PHP)', 'Room', 'Receipt / stay reference', 'Payment kind', 'Cashier', 'Verification'], $digital, [3]);
            $drawers = $this->within(Shift::with(['openedBy', 'closedBy'])->whereNotNull('closed_at'), 'closed_at', $period)->orderBy('closed_at')->get();
            $cashRows = [];
            foreach ($drawers as $drawer) {
                foreach ([1000, 500, 200, 100, 50, 20, 'coins'] as $denomination) {
                    $count = $drawer->denomination_count[$denomination] ?? null;
                    $cashRows[] = [$drawer->id, $this->time($drawer->opened_at), $this->time($drawer->closed_at), $drawer->closedBy?->name,
                        (string) $denomination, $count, $count === null ? null : (float) $count * ($denomination === 'coins' ? 1 : $denomination),
                        (float) $drawer->expected_cash, $drawer->cash_total === null ? null : (float) $drawer->cash_total, $drawer->cash_variance === null ? null : (float) $drawer->cash_variance];
                }
            }
            $sheets[] = $this->sheet('Cash Count & Drawer Check', ['Drawer session', 'Opened', 'Closed', 'Counted by', 'Denomination', 'Pieces / coins PHP', 'Counted value', 'Session expected cash', 'Session declared cash', 'Session variance'], $cashRows, []);
            $notes[] = 'Physical cash counts are saved per closed drawer session. Session totals repeat for reference and must not be summed across denominations. An open shared drawer has no saved closing count.';
            $notes[] = 'Dining categories use saved item names matched to the current catalogue. Unclassified or unmatched amounts remain in Miscell; no department revenue is invented.';
        }

        return ['title' => self::REPORTS[$type], 'description' => self::DESCRIPTIONS[$type], 'type' => $type, 'period' => $period, 'sheets' => $sheets, 'notes' => $notes,
            'property' => config('sedona.hotel_name'), 'generated_at' => now()->setTimezone('Asia/Manila')->format('Y-m-d h:i:s A')];
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function summary(array $period): array
    {
        if ($period['shift'] === 'all') {
            $summary = $this->financials->period($period['start']->setTimezone(config('app.timezone')), $period['end']->setTimezone(config('app.timezone'))->subSecond($period['calendar'] ? 1 : 0), ! $period['calendar']);
            if ($period['finance_day']) {
                $summary['expenses'] = Expense::whereIn('status', ['approved', 'pending_review', 'rejected_paid'])
                    ->whereDate('expense_date', '>=', $period['start_date'])->whereDate('expense_date', '<=', $period['end_date'])->get();
                $total = (float) $summary['expenses']->sum('amount');
                $summary['profit'] = round($summary['profit'] + $summary['totalExpenses'] - $total, 2);
                $summary['totalExpenses'] = $total;
            }

            return $summary;
        }
        $summary = null;
        foreach ($period['windows'] as $window) {
            $part = $this->financials->period($window['start']->setTimezone(config('app.timezone')), $window['end']->setTimezone(config('app.timezone')), true);
            if ($summary === null) {
                $summary = $part;

                continue;
            }
            foreach ($part as $key => $value) {
                $summary[$key] = $value instanceof Collection ? $summary[$key]->concat($value) : round($summary[$key] + $value, 2);
            }
        }

        return $summary;
    }

    /** @param Collection<int, Folio> $folios @param array<string, string> $catalog @return array<string, mixed> */
    private function staySheet(string $title, Collection $folios, array $catalog, bool $transfer = false): array
    {
        $rows = [];
        foreach ($folios as $index => $record) {
            $folio = $transfer ? $record['stay'] : $record;
            $at = $transfer ? $record['cutoff'] : $folio->checked_out_at;
            $charges = $this->charges($folio, $catalog);
            $discount = strtoupper((string) $folio->discount_type);
            $rows[] = [$index + 1, $transfer ? null : $folio->receipt_number, $folio->room?->number, null, $this->time($folio->checked_in_at),
                $this->time($transfer ? $folio->expected_checkout_at : $folio->checked_out_at), $folio->isOpenTime() ? 'Open Time (3h base)' : $folio->rate_tier,
                round($folio->checked_in_at->diffInSeconds($at) / 3600, 2), (int) $folio->extra_hours + (int) $folio->overtime_hours,
                $charges['room'], $charges['kitchen'], $charges['drinks'], $charges['misc'], $charges['extras'],
                $discount === 'DC' ? (float) $folio->discount_amount : 0, in_array($discount, ['SENIOR', 'PWD'], true) ? (float) $folio->discount_amount : 0,
                $folio->discount_id_ref, $transfer || $folio->force_checkout ? 0 : (float) $folio->net_total,
                $transfer ? $record['shift'] : $this->shiftLabel($at), $transfer ? $folio->cashier?->name : ($folio->checkedOutBy?->name ?? $folio->cashier?->name), $transfer ? $this->time($at) : null];
        }

        return $this->sheet($title, ['No.', 'Receipt / O.R. No.', $transfer ? 'Room (current)' : 'Room No.', 'Coupon No.', 'Time In', $transfer ? 'Expected checkout (current)' : 'Time Out', 'Declared Time', 'Hours used (decimal)', 'Excess / extended hours', 'Room bill incl. hours', 'Kitchen bill', 'Drinks bill', 'Miscell purchases', 'Extras', 'Member discount', 'Senior/PWD discount', 'Discount No.', $transfer ? 'Not a new collection' : 'Checkout balance paid', 'Shift', 'Cashier', 'Transfer as of'], $rows, $transfer ? [] : [7, 8, 9, 10, 11, 12, 13, 14, 15, 17]);
    }

    /** @param array<string, mixed> $period @return Collection<int, array<string, mixed>> */
    private function carryovers(array $period): Collection
    {
        $stays = Folio::with(['room', 'guest', 'cashier'])->where('status', '!=', 'voided')
            ->whereHas('room', fn (Builder $query): Builder => $query->where('is_staff_quarters', false))
            ->where('checked_in_at', '<', $period['end']->setTimezone(config('app.timezone')))
            ->where(fn (Builder $query): Builder => $query->whereNull('checked_out_at')->orWhere('checked_out_at', '>=', $period['start']->setTimezone(config('app.timezone'))))
            ->orderBy('checked_in_at')->orderBy('id')->get();
        $carry = collect();
        foreach ($period['windows'] as $window) {
            $cutoff = $window['end']->min(CarbonImmutable::now('Asia/Manila'));
            if ($cutoff->lt($window['start'])) {
                continue;
            }
            foreach ($stays as $stay) {
                if ($stay->checked_in_at->lt($cutoff) && (! $stay->checked_out_at || $stay->checked_out_at->gte($cutoff))) {
                    $carry->push(['stay' => $stay, 'cutoff' => $cutoff, 'shift' => $window['date'].' '.ucfirst($window['shift'])]);
                }
            }
        }

        return $carry;
    }

    /** @param array<string, string> $catalog @return array<string, float> */
    private function orderCharges(Order $order, array $catalog): array
    {
        $amounts = ['room' => 0.0, 'kitchen' => 0.0, 'drinks' => 0.0, 'misc' => (float) $order->total, 'extras' => 0.0];
        foreach ($order->items as $item) {
            $key = $this->department($catalog[mb_strtolower(trim($item->item_name))] ?? '');
            if ($key !== 'misc') {
                $amounts['misc'] -= (float) $item->subtotal;
                $amounts[$key] += (float) $item->subtotal;
            }
        }

        return $amounts;
    }

    /** @param array<string, string> $catalog @return array<string, float> */
    private function charges(Folio $folio, array $catalog): array
    {
        $hours = ((int) $folio->extra_hours + (int) $folio->overtime_hours) * 130;
        $amounts = ['room' => (float) $folio->room_charge + $hours, 'kitchen' => 0.0, 'drinks' => 0.0, 'misc' => (float) $folio->pos_total, 'extras' => (float) $folio->surcharge_total - $hours];
        foreach ($folio->pos_items ?? [] as $item) {
            $key = $this->department($catalog[mb_strtolower(trim($item['name'] ?? ''))] ?? '');
            if ($key !== 'misc') {
                $value = (float) ($item['subtotal'] ?? 0);
                $amounts[$key] += $value;
                $amounts['misc'] -= $value;
            }
        }

        return $amounts;
    }

    private function department(string $category): string
    {
        return match (mb_strtolower(trim($category))) {
            'breakfast', 'favorites', 'noodles', 'rice meals', 'snacks', 'kitchen extras' => 'kitchen',
            'beverages', 'drinks', 'alcoholic drinks', 'beer' => 'drinks',
            default => 'misc',
        };
    }

    /** @return array<string, mixed> */
    private function journal(Collection $folios, Collection $orders): array
    {
        $rows = [];
        foreach ($folios as $folio) {
            $items = collect($folio->pos_items ?? [])->map(fn (array $item): string => ($item['qty'] ?? 1).' × '.($item['name'] ?? 'Item').' = '.number_format((float) ($item['subtotal'] ?? 0), 2))->implode('; ');
            $rows[] = ['at' => $folio->checked_out_at, 'values' => [$folio->receipt_number ?? $folio->transaction_id, $this->time($folio->checked_out_at), $folio->room?->number, $folio->room?->type,
                $folio->isOpenTime() ? 'Open Time' : $folio->rate_tier, $folio->guest?->name, $folio->force_checkout ? 'WRITE-OFF' : $folio->payment_method, $folio->gcash_reference,
                'Room '.number_format((float) $folio->room_charge, 2).'; Surcharges '.number_format((float) $folio->surcharge_total, 2).($items ? '; '.$items : ''),
                (float) $folio->gross_total, null, $folio->force_checkout ? 0 : max(0, (float) $folio->gross_total - (float) $folio->discount_amount),
                $folio->discount_type, (float) $folio->discount_amount, $folio->discount_id_ref, $folio->checkedOutBy?->name ?? $folio->cashier?->name]];
        }
        foreach ($orders as $order) {
            $rows[] = ['at' => $order->created_at, 'values' => [$order->transaction_id, $this->time($order->created_at), null, 'Walk-in POS', null, null, $order->payment_method, $order->gcash_reference,
                $order->items->map(fn ($item): string => $item->quantity.' × '.$item->item_name.' = '.number_format((float) $item->subtotal, 2))->implode('; '),
                (float) $order->total, null, (float) $order->total, null, 0, null, $order->cashier?->name]];
        }

        return $this->sheet('Guest Bills & POS Sales', ['Receipt No.', 'Date & Time', 'Room No.', 'Room Type', 'Declared Stay', 'Guest Name', 'Payment Method', 'GCash Ref #', 'Itemized Orders', 'Subtotal', 'Service Charge (not recorded)', 'Sale after discount', 'Discount Type', 'Discount', 'Discount Ref', 'Cashier'], collect($rows)->sortBy('at')->pluck('values')->values()->all(), [9, 11, 13]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function payments(Collection $entries, Collection $folios, Collection $orders): Collection
    {
        $rows = $entries->map(fn (PaymentEntry $entry): array => ['id' => $entry->id, 'user_id' => $entry->user_id, 'at' => $entry->posted_at, 'cash' => (float) $entry->cash_amount, 'gcash' => (float) $entry->gcash_amount,
            'gcash_reference' => $entry->gcash_reference, 'room' => $entry->folio?->room?->number, 'reference' => $entry->folio?->receipt_number ?? $entry->folio?->transaction_id ?? $entry->order?->transaction_id,
            'kind' => $entry->kind, 'cashier' => $entry->user?->name]);
        $settled = PaymentEntry::whereIn('folio_id', $folios->modelKeys())->where('kind', 'settlement')->pluck('folio_id')->all();
        $sold = PaymentEntry::whereIn('order_id', $orders->modelKeys())->where('kind', 'pos_sale')->pluck('order_id')->all();
        foreach ($folios->whereNotIn('id', $settled)->where('force_checkout', false)->concat($orders->whereNotIn('id', $sold)) as $record) {
            $folio = $record instanceof Folio;
            $rows->push(['id' => null, 'user_id' => $folio ? ($record->checked_out_by ?? $record->user_id) : $record->user_id, 'at' => $folio ? $record->checked_out_at : $record->created_at,
                'cash' => in_array($record->payment_method, ['cash', 'split'], true) ? max(0, (float) $record->cash_tendered - (float) $record->change_due) : 0,
                'gcash' => in_array($record->payment_method, ['gcash', 'split'], true) ? (float) $record->gcash_amount : 0,
                'gcash_reference' => $record->gcash_reference, 'room' => $folio ? $record->room?->number : null, 'reference' => ($folio ? $record->receipt_number : null) ?? $record->transaction_id,
                'kind' => $folio ? 'Legacy settlement' : 'Legacy POS sale', 'cashier' => $folio ? ($record->checkedOutBy?->name ?? $record->cashier?->name) : $record->cashier?->name]);
        }

        return $rows->sortBy('at')->values();
    }

    /** @param Collection<int, array<string, mixed>> $payments @param array<string, mixed> $period @return array<string, mixed> */
    private function cashierCollections(Collection $payments, array $period): array
    {
        $expenses = $this->within(Expense::whereIn('status', ['approved', 'pending_review', 'rejected_paid'])->where('payment_source', 'cash_drawer'), 'created_at', $period)->get();
        $movements = $this->within(CashMovement::query(), 'posted_at', $period)->get();
        $ids = $payments->pluck('user_id')->merge($expenses->pluck('user_id'))->merge($movements->pluck('user_id'))->unique();
        $users = User::whereIn('id', $ids)->pluck('name', 'id');
        $rows = [];
        foreach ($period['windows'] as $window) {
            foreach ($ids as $id) {
                $money = $payments->filter(fn (array $row): bool => (int) $row['user_id'] === (int) $id && $this->contains($row['at'], $window));
                $paid = $expenses->filter(fn (Expense $expense): bool => (int) $expense->user_id === (int) $id && $this->contains($expense->created_at, $window));
                $moved = $movements->filter(fn (CashMovement $move): bool => (int) $move->user_id === (int) $id && $this->contains($move->posted_at, $window));
                if ($money->isEmpty() && $paid->isEmpty() && $moved->isEmpty()) {
                    continue;
                }
                $cash = round($money->sum('cash'), 2);
                $gcash = round($money->sum('gcash'), 2);
                $expense = round($paid->sum('amount'), 2);
                $movement = round($moved->sum('amount'), 2);
                $rows[] = [$window['date'], ucfirst($window['shift']), $users->get($id, 'Cashier not recorded'), $cash, $gcash, round($cash + $gcash, 2),
                    round($money->where('kind', 'deposit')->sum(fn (array $row): float => $row['cash'] + $row['gcash']), 2),
                    round(-$money->where('kind', 'deposit_refund')->sum(fn (array $row): float => $row['cash'] + $row['gcash']), 2),
                    $expense, $movement, round($cash - $expense + $movement, 2)];
            }
        }

        return $this->sheet('Cashier Collections', ['Business date', 'Shift', 'Cashier', 'Net cash received', 'Net GCash received', 'Net total collections', 'Deposits received (included)', 'Deposits refunded (included)', 'Cash drawer expenses', 'Cash added / removed', 'Net cash (excludes opening float)'], $rows, range(3, 10));
    }

    /** @param array<string, mixed> $window */
    private function contains(mixed $at, array $window): bool
    {
        return $at && $at->gte($window['start']) && $at->lt($window['end']);
    }

    private function time(mixed $at): ?string
    {
        return $at?->copy()->setTimezone('Asia/Manila')->format('Y-m-d h:i:s A');
    }

    private function shiftLabel(mixed $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone('Asia/Manila');

        return $local->subMinutes(390)->format('Y-m-d').' '.($local->hour * 60 + $local->minute >= 390 && $local->hour * 60 + $local->minute < 1110 ? 'Day' : 'Night');
    }

    /** @param list<string> $headers @param array<array-key, array<mixed>> $rows @param list<int> $totals @return array<string, mixed> */
    private function sheet(string $name, array $headers, array $rows, array $totals): array
    {
        return compact('name', 'headers', 'rows', 'totals');
    }
}
