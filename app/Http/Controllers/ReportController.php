<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Room;
use App\Services\FinancialSummaryService;
use App\Services\ShiftAccessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, FinancialSummaryService $financials, ShiftAccessService $custody): View
    {
        $weekStart = now()->subDays(7)->startOfDay();
        $today = $financials->period(now()->startOfDay(), now());
        $week = $financials->period($weekStart, now());
        $todayRevenue = $today['netRevenue'];
        $todayCollections = round($today['cashCollections'] + $today['gcashCollections'], 2);
        $weekRevenue = $week['netRevenue'];
        $totalDiscountsGiven = $week['discounts'];
        $totalFoliosThisWeek = Folio::where('status', '!=', 'voided')->whereHas('room', fn ($query) => $query->where('is_staff_quarters', false))
            ->whereBetween('checked_in_at', [$weekStart, now()])->count();
        $roomRevenueWeek = $week['roomRevenue'];
        $overtimeRevenueWeek = $week['overtimeRevenue'] + $week['surchargesRevenue'];
        $posRevenueWeek = $week['diningRevenue'];
        $standardExpenses = $week['expenses']->map(fn ($expense): array => [
            'category' => ucwords(str_replace('_', ' ', $expense->category)),
            'description' => $expense->description, 'amount' => (float) $expense->amount,
        ]);
        if ($week['providerFees'] != 0) {
            $standardExpenses->push(['category' => 'Provider / Bank Fees', 'description' => 'Matched statement fees', 'amount' => $week['providerFees']]);
        }
        $totalOperatingExpenses = round($week['totalExpenses'] + $week['providerFees'], 2);
        $weeklyDeductions = [
            'Room discounts' => $week['discounts'],
            'Approved write-offs' => $week['losses'],
            'POS sales returns' => $week['salesReturns'],
        ];
        $weeklyInvoiceTax = $week['invoiceTax'];
        $grossWeeklyRevenue = $week['grossRevenue'];
        $netWeeklyOperatingProfit = $week['profit'];
        $recentFolios = Folio::with(['room', 'guest', 'cashier'])->latest('checked_in_at')->paginate(15);
        $pastShifts = $custody->visibleShifts($request->user())->whereNotNull('closed_at')->with(['openedBy', 'closedBy'])->latest('created_at')->take(10)->get();
        $rooms = Room::withCount(['folios' => function ($query) use ($weekStart): void {
            $query->where('status', '!=', 'voided')->whereHas('room', fn ($rooms) => $rooms->where('is_staff_quarters', false))
                ->whereBetween('checked_in_at', [$weekStart, now()]);
        }])->orderByNumber()->get();

        return view('reports.index', compact('todayRevenue', 'todayCollections', 'weeklyDeductions', 'weeklyInvoiceTax', 'weekRevenue', 'totalDiscountsGiven',
            'totalFoliosThisWeek', 'roomRevenueWeek', 'overtimeRevenueWeek', 'posRevenueWeek',
            'standardExpenses', 'totalOperatingExpenses', 'grossWeeklyRevenue', 'netWeeklyOperatingProfit',
            'recentFolios', 'pastShifts', 'rooms'));
    }
}
