<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Order;
use App\Models\Room;
use App\Models\Shift;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\FinancialPostingService;
use App\Services\FinancialSummaryService;
use App\Services\ShiftAccessService;
use App\Services\ShiftReportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    public function __construct(private FinancialSummaryService $financials) {}

    /**
     * Profit & Loss (P&L) Statement & Executive BI.
     */
    public function pnl(Request $request): View
    {
        $this->validateDateRange($request);
        $range = $request->query('range', 'this_week');
        $customStart = $request->query('start_date');
        $customEnd = $request->query('end_date');
        $shift = $request->query('shift') ?? 'all';

        [$startDate, $endDate, $rangeLabel] = $this->resolveDateRange($range, $customStart, $customEnd, $shift, $request->query('report_date'));
        $reportDate = $range === 'today' ? $startDate->copy()->setTimezone('Asia/Manila')->toDateString() : null;

        $summary = $this->financials->period($startDate, $endDate, shiftBased: $range === 'today');
        $settledFolios = $summary['folios'];
        $expenses = $summary['expenses'];
        $roomLodgingRev = $summary['roomRevenue'];
        $overtimeRev = $summary['overtimeRevenue'];
        $surchargesRev = $summary['surchargesRevenue'];
        $totalDiningRev = $summary['diningRevenue'];
        $grossRevenue = $summary['grossRevenue'];
        $seniorPwdDiscounts = $summary['discounts'];
        $lossSlips = $summary['losses'];
        $salesReturns = $summary['salesReturns'];
        $netRevenueCollected = $summary['netRevenue'];
        $vatOutputAmount = $summary['invoiceTax'];
        $netVatableSales = round($netRevenueCollected - $vatOutputAmount, 2);
        $expensesByCategory = array_fill_keys(['utilities', 'laundry_linens', 'maintenance_repairs',
            'kitchen_inventory', 'staff_allowances', 'administrative', 'other'], 0.0);
        foreach ($expenses as $expense) {
            $category = array_key_exists($expense->category, $expensesByCategory) ? $expense->category : 'other';
            $expensesByCategory[$category] += (float) $expense->amount;
        }
        $expensesByCategory['provider_fees'] = $summary['providerFees'];
        $totalOpex = $summary['totalExpenses'] + $summary['providerFees'];
        $netOperatingProfit = $summary['profit'];
        $totalCheckIns = $settledFolios->count();
        $totalRooms = Room::where('is_staff_quarters', false)->count();
        $cashCollections = $summary['cashCollections'];
        $gcashCollections = $summary['gcashCollections'];

        return view('accounting.pnl', compact(
            'range', 'rangeLabel', 'startDate', 'endDate', 'shift', 'reportDate',
            'roomLodgingRev', 'overtimeRev', 'surchargesRev', 'totalDiningRev', 'grossRevenue',
            'seniorPwdDiscounts', 'lossSlips', 'salesReturns', 'netRevenueCollected',
            'netVatableSales', 'vatOutputAmount',
            'expensesByCategory', 'totalOpex', 'netOperatingProfit',
            'totalCheckIns', 'totalRooms',
            'cashCollections', 'gcashCollections',
            'settledFolios', 'expenses'
        ));
    }

    /**
     * Operating Expenses & Petty Cash Ledger.
     */
    public function expensesIndex(Request $request): View
    {
        $selectedCategory = $request->query('category', 'all');
        $query = Expense::with('user')->latest('expense_date');

        if ($selectedCategory !== 'all') {
            $query->where('category', $selectedCategory);
        }

        $expenses = $query->paginate(20);

        $thisMonthTotal = (float) Expense::whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year)
            ->whereIn('status', ['approved', 'pending_review', 'rejected_paid'])
            ->sum('amount');

        $todayTotal = (float) Expense::whereDate('expense_date', today())
            ->whereIn('status', ['approved', 'pending_review', 'rejected_paid'])
            ->sum('amount');

        $categories = [
            'utilities' => 'Utilities (Power, Water, Internet)',
            'laundry_linens' => 'Housekeeping & Laundry Linens',
            'maintenance_repairs' => 'Repairs & Property Maintenance',
            'kitchen_inventory' => 'Kitchen & F&B Grocery Restock',
            'staff_allowances' => 'Staff Wages & Duty Allowances',
            'administrative' => 'Administrative & Government Permits',
            'other' => 'Other Sundry Expenses',
        ];

        return view('accounting.expenses', compact(
            'expenses', 'selectedCategory', 'thisMonthTotal', 'todayTotal', 'categories'
        ));
    }

    /**
     * Store New Expense Voucher.
     */
    public function storeExpense(Request $request, ShiftAccessService $shiftAccess, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'expense_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'category' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1|max:500000|decimal:0,2',
            'payment_source' => 'required|in:cash_drawer,petty_cash,bank_transfer',
            'receipt_reference' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'shift_override_reason' => 'nullable|string|max:500',
        ]);

        $result = app(FinancialPostingService::class)->execute($request, 'expense.record', null, $validated, function () use ($validated, $request, $shiftAccess, $audit): array {
            app(BusinessDayService::class)->assertWritable($validated['expense_date']);
            $shift = null;
            if ($validated['payment_source'] === 'cash_drawer' && $validated['expense_date'] !== app(BusinessDayService::class)->date()) {
                throw ValidationException::withMessages(['expense_date' => 'Actual drawer payments must use the current business date.']);
            }
            if ($validated['payment_source'] === 'cash_drawer') {
                $shift = Shift::whereNull('closed_at')->lockForUpdate()->first();
                if (! $shift || $shift->is_frozen) {
                    throw ValidationException::withMessages(['payment_source' => 'Cash drawer expenses require an open, unfrozen shift.']);
                }
                $shiftAccess->authorizeOperation($request->user(), $shift, $validated['shift_override_reason'] ?? null, 'expense.cash_drawer');
            }
            $expense = Expense::create([
                'user_id' => Auth::id() ?? 1,
                'shift_id' => $shift?->id,
                'voucher_number' => Expense::generateVoucherNumber(),
                'expense_date' => $validated['expense_date'],
                'category' => $validated['category'],
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'payment_source' => $validated['payment_source'],
                'receipt_reference' => $validated['receipt_reference'] ?? null,
                'status' => 'pending_review',
                'notes' => $validated['notes'] ?? null,
            ]);
            $audit->record($request, 'expense.recorded', $expense, $expense->only(['voucher_number', 'amount', 'shift_id', 'payment_source', 'status', 'receipt_reference']));
            app(ApprovalService::class)->request($request, 'expense_review', $expense->id, [], 'Review paid expense '.$expense->voucher_number, $expense->receipt_reference);
            if ($shift) {
                $shift->update(['total_expenses' => $this->financials->shift($shift)['drawerExpenses']]);
            }

            return ['expense_id' => $expense->id];
        });

        $expense = Expense::findOrFail($result['expense_id']);

        return redirect()->route('admin.accounting.expenses')
            ->with('success', "Expense voucher {$expense->voucher_number} created for ₱".number_format((float) $expense->amount, 2).'.');
    }

    /**
     * Void or Delete Expense.
     */
    public function deleteExpense(Request $request, Expense $expense, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000', 'evidence_reference' => 'required|string|max:255']);
        app(FinancialPostingService::class)->execute($request, 'approval.expense_reversal', $expense->id, $data, function () use ($request, $expense, $data): array {
            $approval = app(ApprovalService::class)->request($request, 'expense_reversal', $expense->id, [], $data['reason'], $data['evidence_reference']);

            return ['approval_id' => $approval->id];
        });

        return redirect()->route('approvals.index')->with('success', 'Expense retained. Its cash-return reversal awaits review.');
    }

    /**
     * General Financial Audit Journal & Transaction Stream.
     */
    public function ledgerIndex(Request $request): View
    {
        $folios = Folio::with(['room', 'guest', 'cashier'])
            ->latest()
            ->take(50)
            ->get();

        $walkinOrders = Order::with('items')
            ->where('type', 'walkin_pos')
            ->latest()
            ->take(30)
            ->get();

        $expenses = Expense::with('user')
            ->latest('expense_date')
            ->take(30)
            ->get();

        return view('accounting.ledger', compact('folios', 'walkinOrders', 'expenses'));
    }

    /**
     * Shift Reconciliations & Drawer Audits.
     */
    public function shiftsIndex(): View
    {
        $shifts = Shift::with(['openedBy', 'closedBy'])
            ->latest('shift_date')
            ->paginate(20);

        return view('accounting.shifts', compact('shifts'));
    }

    /**
     * Audited Loss Slips & Bad Debt Ledger (FCE-######).
     */
    public function lossesIndex(): View
    {
        $lossFolios = Folio::with(['room', 'guest', 'cashier', 'forcedBy'])
            ->where('force_checkout', true)->where('status', 'checked_out')->whereNotNull('force_by')
            ->latest()
            ->paginate(20);

        $totalWrittenOff = (float) Folio::where('force_checkout', true)->where('status', 'checked_out')->whereNotNull('force_by')->sum('net_total');

        return view('accounting.losses', compact('lossFolios', 'totalWrittenOff'));
    }

    /**
     * Export P&L Financial Report to CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $this->validateDateRange($request);
        $range = $request->query('range', 'this_month');
        $shift = $request->query('shift') ?? 'all';
        [$startDate, $endDate, $rangeLabel] = $this->resolveDateRange($range, $request->query('start_date'), $request->query('end_date'), $shift, $request->query('report_date'));
        $shiftBased = $range === 'today';
        $summary = $this->financials->period($startDate, $endDate, shiftBased: $shiftBased);
        $filename = $shiftBased
            ? 'Sedona_Court_Financial_Report_'.$startDate->copy()->setTimezone('Asia/Manila')->format('Ymd').'_'.$shift.'.csv'
            : 'Sedona_Court_Financial_Report_'.$startDate->format('Ymd').'_to_'.$endDate->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($summary, $rangeLabel, $startDate, $endDate, $shiftBased): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['SEDONA COURT FINANCIAL STATEMENT']);
            $format = $shiftBased ? 'm/d/Y h:i A' : 'm/d/Y';
            fputcsv($handle, ['Period', $rangeLabel, 'From', $startDate->copy()->setTimezone($shiftBased ? 'Asia/Manila' : config('app.timezone'))->format($format), 'To', $endDate->copy()->setTimezone($shiftBased ? 'Asia/Manila' : config('app.timezone'))->format($format)]);
            if ($shiftBased) {
                fputcsv($handle, ['Timezone', 'Asia/Manila', 'Start included; end excluded']);
                fputcsv($handle, ['Expenses counted by recorded time']);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['REVENUE STREAMS', 'AMOUNT (PHP)']);
            foreach (['Room Lodging Base Revenue' => 'roomRevenue', 'Overtime & Extensions' => 'overtimeRevenue',
                'Other Surcharges' => 'surchargesRevenue', 'POS Dining & Beverage Sales' => 'diningRevenue',
                'GROSS REVENUE' => 'grossRevenue', 'Less: Discounts' => 'discounts',
                'Less: Approved Loss Slips' => 'losses', 'Less: Sales Returns' => 'salesReturns', 'NET SETTLED REVENUE' => 'netRevenue', 'Less: Recorded Invoice Tax' => 'invoiceTax'] as $label => $key) {
                fputcsv($handle, [$label, number_format($summary[$key], 2, '.', '')]);
            }
            fputcsv($handle, []);
            fputcsv($handle, ['OPERATING EXPENSES (OPEX)', 'AMOUNT (PHP)']);
            foreach ($summary['expenses']->groupBy('category') as $category => $expenses) {
                $label = ucwords(str_replace('_', ' ', $category));
                if (preg_match('/^[=+@\-\t\r]/', $label)) {
                    $label = "'".$label;
                }
                fputcsv($handle, [$label, number_format($expenses->sum('amount'), 2, '.', '')]);
            }
            fputcsv($handle, ['Matched Provider / Bank Fees', number_format($summary['providerFees'], 2, '.', '')]);
            fputcsv($handle, ['TOTAL OPERATING EXPENSES', number_format($summary['totalExpenses'] + $summary['providerFees'], 2, '.', '')]);
            fputcsv($handle, ['NET OPERATING PROFIT (EBITDA)', number_format($summary['profit'], 2, '.', '')]);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function validateDateRange(Request $request): void
    {
        $request->validate([
            'range' => 'nullable|in:today,this_week,this_month,last_month,custom',
            'shift' => 'nullable|in:all,day,night',
            'report_date' => 'nullable|date_format:Y-m-d',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);
    }

    private function resolveDateRange(string $range, ?string $customStart, ?string $customEnd, string $shift = 'all', ?string $reportDate = null): array
    {
        if ($range === 'today') {
            $period = app(ShiftReportService::class)->period($reportDate, $shift);

            return [
                $period['start']->setTimezone(config('app.timezone')),
                $period['end']->setTimezone(config('app.timezone')),
                $period['label'].' ('.$period['day']->format('M d, Y').')',
            ];
        }

        return match ($range) {
            'this_week' => [
                now()->startOfWeek(),
                now()->endOfWeek(),
                'This Week ('.now()->startOfWeek()->format('M d').' - '.now()->endOfWeek()->format('M d, Y').')',
            ],
            'this_month' => [
                now()->startOfMonth(),
                now()->endOfMonth(),
                'This Month ('.now()->format('F Y').')',
            ],
            'last_month' => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
                'Last Month ('.now()->subMonthNoOverflow()->format('F Y').')',
            ],
            'custom' => [
                $customStart ? Carbon::parse($customStart)->startOfDay() : now()->startOfMonth(),
                $customEnd ? Carbon::parse($customEnd)->endOfDay() : now()->endOfDay(),
                'Custom Range ('.($customStart ?? '').' to '.($customEnd ?? '').')',
            ],
            default => [
                now()->startOfWeek(),
                now()->endOfWeek(),
                'This Week',
            ],
        };
    }
}
