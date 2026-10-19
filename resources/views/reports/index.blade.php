@extends('layouts.app')

@section('title', 'Weekly Financial Analytics & Executive BI')
@section('subtitle', 'Executive Management | Financial Reports, Expense Ledgers & Operating Profit')

@section('top_action')
    <a href="{{ route('dashboard') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; Return to Dashboard
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-7xl mx-auto">

    <!-- KPI Summary Row -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
        <div class="bg-white p-3.5 border-l-4 border-amber-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] tracking-wider block">Today's Gross Collections</span>
            <div class="text-xl font-mono font-black text-amber-700 mt-1">₱{{ number_format($todayRevenue, 2) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Active room settlements + Walk-in POS</div>
        </div>

        <div class="bg-white p-3.5 border-l-4 border-emerald-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] tracking-wider block">7-Day Gross Revenue</span>
            <div class="text-xl font-mono font-black text-emerald-700 mt-1">₱{{ number_format($weekRevenue, 2) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Combined lodging, overtime & dining</div>
        </div>

        <div class="bg-white p-3.5 border-l-4 border-rose-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] tracking-wider block">Senior / PWD Discounts</span>
            <div class="text-xl font-mono font-black text-rose-700 mt-1">₱{{ number_format($totalDiscountsGiven, 2) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">20% Statutory deductions applied</div>
        </div>

        <div class="bg-white p-3.5 border-l-4 border-blue-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] tracking-wider block">7-Day Guest Check-Ins</span>
            <div class="text-xl font-mono font-black text-blue-700 mt-1">{{ $totalFoliosThisWeek }} Check-Ins</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Across Classic, Premium & VIP Suites</div>
        </div>
    </div>

    <!-- Section 1: Weekly Revenue & Operating Profit Engine -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

        <!-- Revenue Breakdown by Category -->
        <div class="lg:col-span-6">
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-3 flex items-center justify-between">
                    <span>WEEKLY REVENUE BREAKDOWN BY STREAM</span>
                    <span class="text-xs text-slate-500 font-mono">Last 7 Days</span>
                </div>

                <div class="space-y-2 text-xs">
                    <div class="flex items-center justify-between p-2 bg-slate-50 border border-slate-200">
                        <span class="font-semibold text-slate-700">Room Base Lodging Charges</span>
                        <span class="font-mono font-bold text-slate-900">₱{{ number_format($roomRevenueWeek, 2) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-2 bg-slate-50 border border-slate-200">
                        <span class="font-semibold text-slate-700">Overtime & Excess Hour Surcharges (₱130/hr)</span>
                        <span class="font-mono font-bold text-purple-800">₱{{ number_format($overtimeRevenueWeek, 2) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-2 bg-slate-50 border border-slate-200">
                        <span class="font-semibold text-slate-700">Kitchen, Beverages & Amenity Sales (POS)</span>
                        <span class="font-mono font-bold text-emerald-800">₱{{ number_format($posRevenueWeek, 2) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-2.5 bg-red-50 border border-red-200 mt-3">
                        <span class="font-bold text-[#421A2B] text-xs">TOTAL GROSS REVENUE:</span>
                        <span class="font-mono font-black text-[#421A2B] text-base">₱{{ number_format($grossWeeklyRevenue, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Operating Expenses & Net Operating Profit -->
        <div class="lg:col-span-6">
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-3 flex items-center justify-between">
                    <span>OPERATING EXPENSES & NET PROFIT</span>
                    <span class="text-xs text-slate-500 font-mono">Recorded This Week</span>
                </div>

                <div class="overflow-x-auto mb-3">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-100 font-bold text-[11px] border-b text-slate-700">
                            <tr>
                                <th class="p-1.5 border-r">Expense Category</th>
                                <th class="p-1.5 border-r">Description</th>
                                <th class="p-1.5 text-right">Amount (₱)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-[11px]">
                            @foreach($standardExpenses as $exp)
                                <tr class="hover:bg-slate-50">
                                    <td class="p-1.5 font-bold text-slate-800 border-r">{{ $exp['category'] }}</td>
                                    <td class="p-1.5 text-slate-600 border-r">{{ $exp['description'] }}</td>
                                    <td class="p-1.5 text-right font-mono font-semibold">{{ number_format($exp['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-slate-100 font-bold">
                                <td colspan="2" class="p-1.5 text-slate-800 uppercase">Total Weekly Operating Expenses:</td>
                                <td class="p-1.5 text-right font-mono text-rose-700">₱{{ number_format($totalOperatingExpenses, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Net Operating Profit Box -->
                <div class="p-3 bg-emerald-50 border border-emerald-300 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-bold uppercase text-emerald-900">Net Weekly Operating Profit</div>
                        <div class="text-[10px] text-emerald-700">Gross Weekly Revenue minus Itemized Expenses</div>
                    </div>
                    <div class="text-xl font-mono font-black text-emerald-800">
                        ₱{{ number_format($netWeeklyOperatingProfit, 2) }}
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Section 2: Cashier Shift Turnovers & Cash Drawer Audits -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-3 flex items-center justify-between">
            <span>CASHIER SHIFT HANDOVERS & BLIND DRAWER AUDITS ({{ count($pastShifts) }})</span>
            <a href="{{ route('shifts.index') }}" class="text-xs text-blue-700 hover:underline font-semibold">View Shift Terminal &rarr;</a>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>SHIFT DATE</th>
                        <th>TYPE</th>
                        <th>OPENED BY</th>
                        <th>CLOSED BY</th>
                        <th>ROOM REV</th>
                        <th>KITCHEN REV</th>
                        <th>SYSTEM GROSS</th>
                        <th>DRAWER COUNT</th>
                        <th>DISCREPANCY</th>
                        <th>HANDOFF NOTES</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pastShifts as $sh)
                        @php
                            $variance = (float) $sh->cash_variance;
                        @endphp
                        <tr>
                            <td class="font-mono">{{ $sh->shift_date->format('m/d/Y') }}</td>
                            <td class="font-bold uppercase text-[10px]">{{ $sh->shift_type }}</td>
                            <td class="font-semibold">{{ $sh->openedBy->name ?? 'Staff' }}</td>
                            <td class="font-semibold">{{ $sh->closedBy->name ?? ($sh->closed_at ? 'Staff' : 'In Progress') }}</td>
                            <td class="font-mono">₱{{ number_format($sh->room_revenue, 2) }}</td>
                            <td class="font-mono">₱{{ number_format($sh->kitchen_revenue, 2) }}</td>
                            <td class="font-mono font-bold">₱{{ number_format($sh->gross_revenue, 2) }}</td>
                            <td class="font-mono font-bold text-slate-900">₱{{ number_format($sh->cash_total, 2) }}</td>
                            <td>
                                @if($variance > 0)
                                    <span class="px-1.5 py-0.5 bg-blue-100 text-blue-800 font-bold text-[10px] font-mono">+₱{{ number_format($variance, 2) }} (Over)</span>
                                @elseif($variance < 0)
                                    <span class="px-1.5 py-0.5 bg-rose-100 text-rose-800 font-bold text-[10px] font-mono">-₱{{ number_format(abs($variance), 2) }} (Short)</span>
                                @else
                                    <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-800 font-bold text-[10px] font-mono">Balanced</span>
                                @endif
                            </td>
                            <td class="text-left pl-2 text-[11px] text-slate-600 max-w-xs truncate">{{ $sh->handoff_notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-4 text-center text-slate-400">No shift records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 3: Room Utilization & Turnover Frequency (32 Rooms) -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-3">
            ROOM OCCUPANCY & TURNOVER FREQUENCY (32 ROOMS)
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-8 gap-2">
            @foreach($rooms as $rm)
                <div class="p-2 border border-slate-200 bg-slate-50 text-center">
                    <div class="font-mono font-black text-sm text-slate-900">Room {{ $rm->number }}</div>
                    <div class="text-[10px] text-slate-500 font-medium">Floor {{ $rm->floor }} &bull; {{ $rm->type }}</div>
                    <div class="mt-1 text-xs">
                        <span class="text-[10px] text-slate-500 block">7-Day Stays:</span>
                        <span class="font-mono font-bold text-[#421A2B]">{{ $rm->folios_count }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Section 4: Recent Guest Folios Ledger -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-3">
            RECENT GUEST FOLIOS & TRANSACTION AUDIT LEDGER
        </div>
        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>FOLIO #</th>
                        <th>ROOM</th>
                        <th>GUEST NAME</th>
                        <th>TIER</th>
                        <th>GROSS TOTAL</th>
                        <th>DISCOUNT</th>
                        <th>NET SETTLED</th>
                        <th>PAYMENT</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentFolios as $f)
                        <tr>
                            <td class="font-mono font-bold text-[#421A2B]">{{ $f->transaction_id }}</td>
                            <td class="font-bold">Room {{ $f->room->number ?? 'N/A' }}</td>
                            <td class="font-medium text-left pl-3">{{ $f->guest->name ?? 'Guest' }}</td>
                            <td class="font-mono font-bold uppercase text-[10px]">{{ $f->rate_tier }}</td>
                            <td class="font-mono">₱{{ number_format($f->gross_total, 2) }}</td>
                            <td class="font-mono text-rose-700">-₱{{ number_format($f->discount_amount, 2) }}</td>
                            <td class="font-mono font-bold text-emerald-800">₱{{ number_format($f->net_total, 2) }}</td>
                            <td class="font-mono uppercase text-[10px]">{{ $f->payment_method ?? 'CASH' }}</td>
                            <td>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $f->status === 'checked_out' ? 'bg-slate-200 text-slate-700' : 'bg-rose-100 text-rose-800' }}">
                                    {{ $f->status }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-4 text-center text-slate-400">No folio transactions recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($recentFolios->hasPages())
            <div class="p-2 border-t mt-2">
                {{ $recentFolios->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
