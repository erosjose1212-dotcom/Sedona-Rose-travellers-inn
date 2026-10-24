<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FinancialSummaryService
{
    /** @return array<string, mixed> */
    public function period(CarbonInterface $start, CarbonInterface $end, bool $shiftBased = false): array
    {
        $withinPeriod = fn (Builder $query, string $column): Builder => $query
            ->where($column, '>=', $start)->where($column, $shiftBased ? '<' : '<=', $end);
        $folios = $withinPeriod(Folio::with(['room', 'guest', 'cashier'])->where('status', 'checked_out'), 'checked_out_at')->get();
        $walkinOrders = $withinPeriod(Order::where('type', 'walkin_pos'), 'created_at')->get();
        $expenses = Expense::whereIn('status', ['approved', 'pending_review', 'rejected_paid'])
            ->when($shiftBased,
                fn (Builder $query): Builder => $withinPeriod($query, 'created_at'),
                fn (Builder $query): Builder => $query->whereDate('expense_date', '>=', $start->toDateString())->whereDate('expense_date', '<=', $end->toDateString())
            )->get();
        $revenue = $this->revenue($folios, $walkinOrders);
        $refunds = $withinPeriod(PaymentEntry::where('kind', 'pos_refund'), 'posted_at')->get();
        $returns = -(float) $refunds->sum(fn (PaymentEntry $entry): float => (float) $entry->cash_amount + (float) $entry->gcash_amount);
        $revenue['netRevenue'] = round($revenue['netRevenue'] - $returns, 2);
        $revenue['salesReturns'] = $returns;
        $invoiceTax = (float) Invoice::where(fn ($query) => $query->whereIn('folio_id', $folios->modelKeys())->orWhereIn('order_id', $walkinOrders->modelKeys()))->get()->sum(fn ($invoice): float => $invoice->document['tax_cents'] / 100);
        $invoiceTax -= $refunds->sum(fn (PaymentEntry $entry): float => InvoiceService::refundTaxCents($entry) / 100);
        $providerFees = (float) $withinPeriod(StatementLine::query(), 'posted_at')->whereIn('id', DB::table('statement_matches')->select('statement_line_id'))->sum('fee_amount');
        $totalExpenses = (float) $expenses->sum('amount');
        $entries = $withinPeriod(PaymentEntry::query(), 'posted_at')->get();
        [$legacyCash, $legacyGcash] = $this->tenders($this->legacySettlements($start, $end, ! $shiftBased));
        [$posCash, $posGcash] = $this->tenders($this->legacyOrders($walkinOrders));
        $cashCollections = round((float) $entries->sum('cash_amount') + $legacyCash + $posCash, 2);
        $gcashCollections = round((float) $entries->sum('gcash_amount') + $legacyGcash + $posGcash, 2);

        return $revenue + compact('folios', 'expenses', 'totalExpenses', 'cashCollections', 'gcashCollections', 'invoiceTax', 'providerFees')
            + ['profit' => round($revenue['netRevenue'] - $totalExpenses - $invoiceTax - $providerFees, 2)];
    }

    /** @return array<string, mixed> */
    public function shift(Shift $shift): array
    {
        $end = $shift->closed_at ?? now();
        $entries = PaymentEntry::where(function (Builder $query) use ($shift, $end): void {
            $query->where('shift_id', $shift->id)->orWhere(function (Builder $unassigned) use ($shift, $end): void {
                $unassigned->whereNull('shift_id')->where('posted_at', '>=', $shift->opened_at)
                    ->where('posted_at', $shift->closed_at ? '<' : '<=', $end);
            });
        })->get();
        $ownedFolioIds = $entries->whereIn('kind', ['settlement', 'write_off'])->pluck('folio_id')->filter();
        $folios = Folio::where('status', 'checked_out')->where(function (Builder $query) use ($shift, $end, $ownedFolioIds): void {
            $query->whereIn('id', $ownedFolioIds)->orWhere(function (Builder $legacy) use ($shift, $end): void {
                $legacy->whereNotIn('id', PaymentEntry::whereIn('kind', ['settlement', 'write_off'])->whereNotNull('folio_id')->select('folio_id'))
                    ->where('checked_out_at', '>=', $shift->opened_at)->where('checked_out_at', $shift->closed_at ? '<' : '<=', $end);
            });
        })->get();
        [$legacyCash, $legacyGcash] = $this->tenders($this->legacySettlements($shift->opened_at, $end, ! $shift->closed_at));
        $orders = Order::where('type', 'walkin_pos')->where(function (Builder $query) use ($shift, $end): void {
            $query->where('shift_id', $shift->id)->orWhere(function (Builder $unassigned) use ($shift, $end): void {
                $unassigned->whereNull('shift_id')->where('created_at', '>=', $shift->opened_at)
                    ->where('created_at', $shift->closed_at ? '<' : '<=', $end);
            });
        })->get();
        [$posCash, $posGcash] = $this->tenders($this->legacyOrders($orders));
        $cashSales = round((float) $entries->sum('cash_amount') + $legacyCash + $posCash, 2);
        $gcashSales = round((float) $entries->sum('gcash_amount') + $legacyGcash + $posGcash, 2);
        $shiftExpenses = Expense::whereIn('status', ['approved', 'pending_review', 'rejected_paid'])->where('payment_source', 'cash_drawer')
            ->where(function (Builder $query) use ($shift, $end): void {
                $query->where('shift_id', $shift->id)->orWhere(function (Builder $unassigned) use ($shift, $end): void {
                    $unassigned->whereNull('shift_id')->where('created_at', '>=', $shift->opened_at)
                        ->where('created_at', $shift->closed_at ? '<' : '<=', $end);
                });
            })->latest()->get();
        $drawerExpenses = (float) $shiftExpenses->sum('amount');
        $openingFloat = (float) $shift->opening_float;
        $cashMovements = (float) CashMovement::where('shift_id', $shift->id)->sum('amount');
        $expectedCash = round($openingFloat + $cashSales - $drawerExpenses + $cashMovements, 2);

        return $this->revenue($folios, $orders) + compact('shiftExpenses', 'openingFloat', 'cashSales', 'gcashSales', 'expectedCash', 'drawerExpenses');
    }

    /** @return array<string, mixed> */
    public function operatorShift(Shift $shift, User $operator, CarbonInterface $start, CarbonInterface $end): array
    {
        $withinPeriod = function (Builder $query, string $column) use ($shift, $start, $end): Builder {
            return $query->where($column, '>=', $start)->where($column, '<', $end)
                ->where($column, '>=', $shift->opened_at)
                ->when($shift->closed_at,
                    fn (Builder $query): Builder => $query->where($column, '<', $shift->closed_at),
                    fn (Builder $query): Builder => $query->where($column, '<=', now()));
        };
        $owned = fn (Builder $query): Builder => $query->where('user_id', $operator->id)
            ->where(fn (Builder $query): Builder => $query->where('shift_id', $shift->id)->orWhereNull('shift_id'));
        $entries = $withinPeriod($owned(PaymentEntry::query()), 'posted_at')->get();
        $legacyFolios = $this->legacySettlements($start, $end, false)->filter(fn (Folio $folio): bool => (int) ($folio->checked_out_by ?? $folio->user_id) === (int) $operator->id
            && $folio->checked_out_at->greaterThanOrEqualTo($shift->opened_at)
            && $folio->checked_out_at->lessThan($shift->closed_at ?? now()->addSecond()));
        [$legacyCash, $legacyGcash] = $this->tenders($legacyFolios);
        $orders = $withinPeriod($owned(Order::where('type', 'walkin_pos')), 'created_at')->get();
        [$posCash, $posGcash] = $this->tenders($this->legacyOrders($orders));
        $cashSales = round((float) $entries->sum('cash_amount') + $legacyCash + $posCash, 2);
        $gcashSales = round((float) $entries->sum('gcash_amount') + $legacyGcash + $posGcash, 2);
        $shiftExpenses = $withinPeriod($owned(Expense::whereIn('status', ['approved', 'pending_review', 'rejected_paid'])
            ->where('payment_source', 'cash_drawer')), 'created_at')->latest()->get();
        $drawerExpenses = (float) $shiftExpenses->sum('amount');
        $cashMovements = $withinPeriod(CashMovement::where('user_id', $operator->id)->where('shift_id', $shift->id), 'posted_at')->orderBy('posted_at')->get();

        return compact('cashSales', 'gcashSales', 'shiftExpenses', 'drawerExpenses', 'cashMovements')
            + ['netCash' => round($cashSales - $drawerExpenses + (float) $cashMovements->sum('amount'), 2)];
    }

    /** @return array<string, float> */
    private function revenue(Collection $folios, Collection $orders): array
    {
        $roomRevenue = (float) $folios->sum('room_charge');
        $overtimeRevenue = (float) $folios->sum(fn (Folio $folio): float => ($folio->extra_hours + ($folio->overtime_hours ?? 0)) * 130);
        $surchargesRevenue = round((float) $folios->sum('surcharge_total') - $overtimeRevenue, 2);
        $diningRevenue = (float) $folios->sum('pos_total') + (float) $orders->sum('total');
        $grossRevenue = (float) $folios->sum('gross_total') + (float) $orders->sum('total');
        $discounts = (float) $folios->sum('discount_amount');
        $losses = (float) $folios->filter(fn (Folio $folio): bool => $folio->force_checkout && $folio->force_by !== null)->sum('net_total');
        $netRevenue = round($grossRevenue - $discounts - $losses, 2);

        return compact('roomRevenue', 'overtimeRevenue', 'surchargesRevenue', 'diningRevenue', 'grossRevenue', 'discounts', 'losses', 'netRevenue');
    }

    private function legacySettlements(CarbonInterface $start, CarbonInterface $end, bool $inclusiveEnd = true): Collection
    {
        return Folio::where('status', 'checked_out')->where('force_checkout', false)
            ->where('checked_out_at', '>=', $start)->where('checked_out_at', $inclusiveEnd ? '<=' : '<', $end)
            ->whereNotIn('id', PaymentEntry::where('kind', 'settlement')->whereNotNull('folio_id')->select('folio_id'))->get();
    }

    private function legacyOrders(Collection $orders): Collection
    {
        $journaledIds = PaymentEntry::where('kind', 'pos_sale')->whereIn('order_id', $orders->modelKeys())->pluck('order_id')->all();

        return $orders->whereNotIn('id', $journaledIds);
    }

    /** @return array{float, float} */
    private function tenders(Collection $records): array
    {
        $cash = 0.0;
        $gcash = 0.0;
        foreach ($records as $record) {
            if (in_array($record->payment_method, ['cash', 'split'], true)) {
                $cash += max(0, (float) $record->cash_tendered - (float) $record->change_due);
            }
            if (in_array($record->payment_method, ['gcash', 'split'], true)) {
                $gcash += (float) $record->gcash_amount;
            }
        }

        return [round($cash, 2), round($gcash, 2)];
    }
}
