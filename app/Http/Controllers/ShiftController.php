<?php

namespace App\Http\Controllers;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\BusinessDayService;
use App\Services\FinancialPostingService;
use App\Services\FinancialSummaryService;
use App\Services\ShiftAccessService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function __construct(private FinancialSummaryService $financials, private ShiftAccessService $shiftAccess) {}

    public function index(Request $request): View
    {
        $activeShift = Shift::with(['openedBy', 'closedBy'])->whereNull('closed_at')->latest()->first();
        $shiftExpenses = collect();
        $openingFloat = 5000.0;
        $cashRevenue = 0.0;
        $gcashCollections = 0.0;
        $totalExpenses = 0.0;
        $expectedCash = 5000.0;
        $canOperateActiveShift = $activeShift && $request->user()->can('operate-shift', $activeShift);
        if ($canOperateActiveShift) {
            $summary = $this->financials->shift($activeShift);
            $shiftExpenses = $summary['shiftExpenses'];
            $openingFloat = $summary['openingFloat'];
            $cashRevenue = $summary['cashSales'];
            $gcashCollections = $summary['gcashSales'];
            $totalExpenses = $summary['drawerExpenses'];
            $expectedCash = $summary['expectedCash'];
        }
        $pastShifts = $this->shiftAccess->visibleShifts($request->user())->with(['openedBy', 'closedBy'])
            ->whereNotNull('closed_at')->latest('closed_at')->latest('id')->paginate(10);
        $pendingHandovers = Shift::whereIn('handover_status', ['pending', 'disputed', 'variance_approved'])->whereNotNull('closed_at');
        if (! ($request->user()->hasAnyRole(['admin', 'owner']) && $request->user()->can('view_all_shifts'))) {
            $pendingHandovers->where(function ($query) use ($request): void {
                $query->where('opened_by', $request->user()->id)->orWhere('closed_by', $request->user()->id)
                    ->orWhere('incoming_user_id', $request->user()->id)->orWhereNull('incoming_user_id');
            });
        }
        $pendingHandovers = config('sedona.shift_handover_enabled') ? $pendingHandovers->orderBy('id')->get() : collect();
        $drawerStatus = $this->shiftAccess->paymentStatus($request->user());

        return view('shifts.index', compact('activeShift', 'pastShifts', 'shiftExpenses', 'openingFloat', 'cashRevenue', 'gcashCollections', 'totalExpenses', 'expectedCash', 'canOperateActiveShift', 'pendingHandovers', 'drawerStatus'));
    }

    public function openShift(Request $request, AuditService $audit): RedirectResponse
    {
        if ($request->filled('checkout_folio_id')) {
            Gate::authorize('manage_folios');
            Gate::authorize('record_payments');
        }
        $validated = $request->validate([
            'opening_float' => 'required|numeric|min:0|max:100000|decimal:0,2',
            'checkout_folio_id' => 'nullable|integer|exists:folios,id',
        ], ['opening_float.required' => 'Enter the cash actually in the drawer. Enter 0 if it is empty.']);
        if (! empty($validated['checkout_folio_id']) && ! Folio::findOrFail($validated['checkout_folio_id'])->isActive()) {
            throw ValidationException::withMessages(['checkout_folio_id' => 'This stay has already been settled. Start your shift from Shifts & Drawer.']);
        }
        try {
            app(FinancialPostingService::class)->execute($request, 'shift.open', null, $validated, function () use ($validated, $request, $audit): array {
                app(BusinessDayService::class)->assertWritable();
                if (config('sedona.shift_handover_enabled') && Shift::whereIn('handover_status', ['pending', 'disputed', 'variance_approved'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['shift' => 'Accept the preceding drawer handover before opening another shift.']);
                }
                if (Shift::whereNull('closed_at')->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['shift' => 'There is already an open shift in progress.']);
                }
                $float = (float) $validated['opening_float'];
                $shift = Shift::create([
                    'opened_by' => $request->user()->id, 'shift_type' => Shift::typeForTime(now()),
                    'shift_date' => now()->toDateString(), 'opened_at' => now(), 'opening_float' => $float,
                    'cash_total' => $float, 'expected_cash' => $float, 'is_frozen' => false,
                ]);
                $audit->record($request, 'shift.opened', $shift, ['opening_float' => $float]);

                return ['shift_id' => $shift->id];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['shift' => 'There is already an open shift in progress.']);
        }

        if (! empty($validated['checkout_folio_id'])) {
            return redirect()->route('checkout.process', $validated['checkout_folio_id'])->with('success', 'Cashier shift started. You can now record this checkout payment.');
        }

        return redirect()->route('shifts.index')->with('success', 'Cashier shift started. Payments will be recorded in this drawer.');
    }

    public function storeExpense(Request $request, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:200', 'amount' => 'required|numeric|min:1|max:500000',
            'category' => 'required|string|max:50', 'receipt_reference' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'shift_override_reason' => 'nullable|string|max:500',
        ]);
        app(FinancialPostingService::class)->execute($request, 'expense.drawer', null, $validated, function () use ($validated, $request, $audit): array {
            app(BusinessDayService::class)->assertWritable();
            $shift = Shift::whereNull('closed_at')->lockForUpdate()->first();
            if (! $shift || $shift->is_frozen) {
                throw ValidationException::withMessages(['shift' => 'Cannot record expense without an open, unfrozen shift.']);
            }
            $this->shiftAccess->authorizeOperation($request->user(), $shift, $validated['shift_override_reason'] ?? null, 'expense.cash_drawer');
            unset($validated['shift_override_reason']);
            $expense = Expense::create([
                ...$validated, 'user_id' => $request->user()->id, 'shift_id' => $shift->id,
                'voucher_number' => Expense::generateVoucherNumber(), 'expense_date' => app(BusinessDayService::class)->date(),
                'payment_source' => 'cash_drawer', 'status' => 'pending_review',
            ]);
            $audit->record($request, 'expense.recorded', $expense, $expense->only(['voucher_number', 'amount', 'shift_id', 'payment_source', 'status', 'receipt_reference']));
            app(ApprovalService::class)->request($request, 'expense_review', $expense->id, [], 'Review paid expense '.$expense->voucher_number, $expense->receipt_reference);
            $shift->update(['total_expenses' => $this->financials->shift($shift)['drawerExpenses']]);

            return ['expense_id' => $expense->id];
        });

        return redirect()->route('shifts.index')->with('success', 'Shift expense recorded from the cash drawer.');
    }

    public function closeShift(Request $request, Shift $shift, AuditService $audit): RedirectResponse
    {
        abort_if(config('sedona.continuous_shift_enabled'), 409, 'The continuous cashier shift stays open. Sign out when you finish; the next cashier uses the same drawer.');
        Gate::authorize('operate-shift', $shift);
        $validated = $request->validate([
            'denominations' => 'required|array:1000,500,200,100,50,20,coins',
            'denominations.1000' => 'nullable|integer|min:0|max:1000000',
            'denominations.500' => 'nullable|integer|min:0|max:1000000',
            'denominations.200' => 'nullable|integer|min:0|max:1000000',
            'denominations.100' => 'nullable|integer|min:0|max:1000000',
            'denominations.50' => 'nullable|integer|min:0|max:1000000',
            'denominations.20' => 'nullable|integer|min:0|max:1000000',
            'denominations.coins' => 'nullable|numeric|min:0|max:1000000|decimal:0,2',
            'handoff_notes' => 'nullable|string|max:1000',
            'incoming_user_id' => config('sedona.shift_handover_enabled') ? 'nullable|integer|exists:users,id' : 'exclude',
            'shift_override_reason' => 'nullable|string|max:500',
        ]);
        DB::transaction(function () use ($validated, $shift, $request, $audit): void {
            $shift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->closed_at || $shift->is_frozen) {
                throw ValidationException::withMessages(['shift' => 'This shift is closed or frozen.']);
            }
            $this->shiftAccess->authorizeOperation($request->user(), $shift, $validated['shift_override_reason'] ?? null, 'shift.close');
            if (! empty($validated['incoming_user_id'])) {
                $incoming = User::findOrFail($validated['incoming_user_id']);
                if (! $incoming->is_active || ! $incoming->can('manage_shifts') || $incoming->id === $shift->opened_by) {
                    throw ValidationException::withMessages(['incoming_user_id' => 'Choose a different active operator with drawer access.']);
                }
            }
            app(BusinessDayService::class)->assertWritable();
            $summary = $this->financials->shift($shift);
            $countedCash = 0;
            foreach ($validated['denominations'] as $denomination => $count) {
                $countedCash += ($denomination === 'coins' ? 1 : (int) $denomination) * $count;
            }
            $shift->update([
                'closed_by' => $request->user()->id, 'closed_at' => now(),
                'denomination_count' => $validated['denominations'], 'cash_total' => $countedCash,
                'total_expenses' => $summary['drawerExpenses'], 'expected_cash' => $summary['expectedCash'],
                'cash_variance' => round($countedCash - $summary['expectedCash'], 2),
                'gcash_total' => $summary['gcashSales'], 'room_revenue' => $summary['roomRevenue'] + $summary['overtimeRevenue'] + $summary['surchargesRevenue'],
                'kitchen_revenue' => $summary['diningRevenue'], 'gross_revenue' => $summary['grossRevenue'],
                'handoff_notes' => $validated['handoff_notes'] ?? null, 'incoming_user_id' => $validated['incoming_user_id'] ?? null,
                'handover_status' => config('sedona.shift_handover_enabled') ? 'pending' : null,
            ]);
            if (BillingService::cents($shift->cash_variance) !== 0) {
                app(ApprovalService::class)->request($request, 'shift_variance', $shift->id, [], 'Review outgoing drawer variance', $validated['handoff_notes'] ?? null);
            }
            $audit->record($request, 'shift.closed', $shift, $shift->only(['opening_float', 'cash_total', 'gcash_total', 'expected_cash', 'cash_variance', 'total_expenses', 'denomination_count', 'closed_by']));
        });

        return redirect()->route('shifts.index')->with('success', 'Shift closed and drawer reconciliation recorded.');
    }

    public function showRemittanceSlip(Shift $shift): View
    {
        Gate::authorize('view-shift', $shift);
        $shift->load(['openedBy', 'closedBy']);
        $summary = $this->financials->shift($shift);
        $cashSales = $summary['cashSales'];
        $gcashSales = $shift->closed_at ? (float) $shift->gcash_total : $summary['gcashSales'];
        $totalRevenue = $cashSales + $gcashSales;
        $shiftExpenses = $summary['shiftExpenses'];
        $totalExpenses = $shift->closed_at ? (float) $shift->total_expenses : $summary['drawerExpenses'];
        $cashMovements = CashMovement::where('shift_id', $shift->id)->orderBy('posted_at')->orderBy('id')->get();
        $cashMovementTotal = (float) $cashMovements->sum('amount');
        $openingFloat = $summary['openingFloat'];
        $expectedCash = $shift->closed_at ? (float) $shift->expected_cash : $summary['expectedCash'];
        $actualCash = $shift->closed_at ? (float) $shift->cash_total : $expectedCash;
        $variance = $shift->closed_at ? (float) $shift->cash_variance : 0.0;
        $rooms = Room::where('is_staff_quarters', false)->get();
        $occupiedCount = $rooms->where('status', 'occupied')->count();
        $availableCount = $rooms->where('status', 'available')->count();
        $maintenanceCount = $rooms->where('status', 'maintenance')->count();
        $incomingCashier = $shift->accepted_by ? User::find($shift->accepted_by)?->name
            : (config('sedona.shift_handover_enabled') ? 'Acceptance pending' : 'Not required');

        return view('shifts.remittance_slip', compact('shift', 'cashSales', 'gcashSales', 'totalRevenue',
            'shiftExpenses', 'totalExpenses', 'cashMovements', 'cashMovementTotal', 'openingFloat', 'expectedCash', 'actualCash', 'variance',
            'occupiedCount', 'availableCount', 'maintenanceCount', 'incomingCashier'));
    }
}
