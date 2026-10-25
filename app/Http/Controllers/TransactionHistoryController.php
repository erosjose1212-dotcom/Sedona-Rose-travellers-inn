<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Services\AuthenticationAuditService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TransactionHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $propertyAccess = $this->propertyAccess($request);
        $entries = DB::table('payment_entries as p')->leftJoin('folios as f', 'f.id', '=', 'p.folio_id')->leftJoin('orders as o', 'o.id', '=', 'p.order_id')
            ->selectRaw("p.id, p.user_id, p.shift_id, p.kind, p.cash_amount, p.gcash_amount, p.gcash_reference, p.posted_at, COALESCE(f.receipt_number, f.transaction_id, o.transaction_id, 'Unknown source') as reference, 'journal' as origin");
        $legacyOrders = DB::table('orders as o')->where('o.type', 'walkin_pos')
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('payment_entries as p')->whereColumn('p.order_id', 'o.id')->where('p.kind', 'pos_sale'))
            ->selectRaw("o.id, o.user_id, o.shift_id, 'pos_sale' as kind, CASE WHEN o.payment_method = 'cash' THEN o.cash_tendered - o.change_due ELSE 0 END as cash_amount, o.gcash_amount, o.gcash_reference, o.created_at as posted_at, o.transaction_id as reference, 'legacy_order' as origin");
        $legacyFolios = DB::table('folios as f')->where('f.status', 'checked_out')->where('f.force_checkout', false)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('payment_entries as p')->whereColumn('p.folio_id', 'f.id')->where('p.kind', 'settlement'))
            ->selectRaw("f.id, COALESCE(f.checked_out_by, f.user_id) as user_id, NULL as shift_id, 'settlement' as kind, CASE WHEN f.payment_method IN ('cash', 'split') THEN f.cash_tendered - f.change_due ELSE 0 END as cash_amount, CASE WHEN f.payment_method IN ('gcash', 'split') THEN f.gcash_amount ELSE 0 END as gcash_amount, f.gcash_reference, f.checked_out_at as posted_at, COALESCE(f.receipt_number, f.transaction_id) as reference, 'legacy_folio' as origin");
        $expenses = DB::table('expenses as e')->whereIn('e.status', ['approved', 'pending_review', 'rejected_paid'])->where('e.payment_source', 'cash_drawer')
            ->selectRaw("e.id, e.user_id, e.shift_id, 'drawer_expense' as kind, -e.amount as cash_amount, 0 as gcash_amount, NULL as gcash_reference, e.created_at as posted_at, e.description as reference, 'expense' as origin");
        $movements = DB::table('cash_movements as c')
            ->selectRaw("c.id, c.user_id, c.shift_id, c.kind, c.amount as cash_amount, 0 as gcash_amount, NULL as gcash_reference, c.posted_at, c.reference, 'custody' as origin");
        $query = DB::query()->fromSub($entries->unionAll($legacyOrders)->unionAll($legacyFolios)->unionAll($expenses)->unionAll($movements), 't')->leftJoin('users as u', 'u.id', '=', 't.user_id')->select('t.*', 'u.name as actor');
        if (! $propertyAccess) {
            $query->where('t.user_id', $request->user()->id);
        }
        $this->applyFilters($query, $filters, 't.posted_at');
        if (! empty($filters['kind'])) {
            $query->where('t.kind', $filters['kind']);
        }
        if (! empty($filters['shift_id'])) {
            $query->where('t.shift_id', $filters['shift_id']);
        }
        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(fn (Builder $q) => $q->where('t.reference', 'like', $search)->orWhere('t.gcash_reference', 'like', $search)
                ->orWhereIn('t.reference', Folio::select('receipt_number')->where('transaction_id', 'like', $search)->whereNotNull('receipt_number')));
        }
        $transactions = $query->orderByDesc('t.posted_at')->orderByDesc('t.id')->orderBy('t.origin')->paginate(30)->withQueryString();
        $refundLinks = DB::table('payment_refund_allocations')->whereIn('refund_entry_id', $transactions->getCollection()->where('origin', 'journal')->pluck('id'))->get()->groupBy('refund_entry_id');
        $matchedPaymentIds = DB::table('statement_matches')->whereIn('payment_entry_id', $transactions->getCollection()->where('origin', 'journal')->pluck('id'))->pluck('payment_entry_id')->all();

        return view('accounting.transactions', compact('transactions', 'filters', 'propertyAccess', 'refundLinks', 'matchedPaymentIds'));
    }

    public function audit(Request $request): View
    {
        $filters = $this->filters($request);
        $propertyAccess = $this->propertyAccess($request);
        $query = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('folios as f', fn (JoinClause $join) => $join->on('f.id', '=', 'a.auditable_id')->where('a.auditable_type', Folio::class))
            ->leftJoin('rooms as r', 'r.id', '=', 'f.room_id')
            ->select('a.*', 'u.name as actor', 'r.number as room_number', 'f.transaction_id as stay_reference');
        if (! $propertyAccess) {
            $query->where('a.user_id', $request->user()->id);
            $query->whereNotIn('a.event', AuthenticationAuditService::EVENTS);
        }
        $this->applyFilters($query, $filters, 'a.created_at');
        if (! empty($filters['room_id'])) {
            $query->where('f.room_id', $filters['room_id']);
        }
        if (! empty($filters['folio_id'])) {
            $query->where('f.id', $filters['folio_id']);
        }
        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(fn (Builder $q) => $q->where('a.event', 'like', $search)->orWhere('a.new_values', 'like', $search)
                ->orWhere('f.transaction_id', 'like', $search)->orWhere('f.receipt_number', 'like', $search));
        }
        $printSummaries = $propertyAccess ? (clone $query)->where('a.event', 'billing.print_requested')->whereNotNull('f.id')
            ->select('f.id as folio_id', 'f.transaction_id as stay_reference', 'r.number as room_number')
            ->selectRaw('COUNT(a.id) as print_count, MIN(a.created_at) as first_print_at, MAX(a.created_at) as last_print_at')
            ->groupBy('f.id', 'f.transaction_id', 'r.number')->orderByDesc('last_print_at')->orderByDesc('f.id')
            ->paginate(10, ['*'], 'print_page')->withQueryString() : null;
        $events = $query->orderByDesc('a.created_at')->orderByDesc('a.id')->paginate(30)->withQueryString();

        return view('accounting.audit', compact('events', 'filters', 'propertyAccess', 'printSummaries'));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => $request->filled('from') ? 'nullable|date_format:Y-m-d|after_or_equal:from' : 'nullable|date_format:Y-m-d', 'q' => 'nullable|string|max:100', 'kind' => 'nullable|in:deposit,deposit_refund,settlement,write_off,pos_sale,pos_refund,drawer_expense,cash_drop,float_addition,bank_deposit', 'shift_id' => 'nullable|integer|min:1', 'room_id' => 'nullable|integer|min:1', 'folio_id' => 'nullable|integer|min:1']);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters, string $dateColumn): void
    {
        if (! empty($filters['from'])) {
            $query->where($dateColumn, '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where($dateColumn, '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
    }

    private function propertyAccess(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'owner']) && $request->user()->can('view_all_shifts');
    }
}
