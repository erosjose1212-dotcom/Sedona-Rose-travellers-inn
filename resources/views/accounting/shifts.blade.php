@extends('layouts.app')

@section('title', config('sedona.shift_handover_enabled') ? 'Shift Drawer Audits & Handover Reconciliations' : 'Shift Drawer Audits & Reconciliations')
@section('subtitle', 'Accounting & Financial Hub | Cashier Float Reconciliations & Drawer Discrepancy Audits')

@section('top_action')
    <a href="{{ route('shifts.index') }}" class="bg-[#0284c7] hover:bg-[#0369a1] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm">
        Go to Cashier Shift Terminal &rarr;
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-7xl mx-auto">

    <!-- Header Summary -->
    <div class="bg-white p-3 border-l-4 border-[#421A2B] shadow-sm flex items-center justify-between">
        <div>
            <h1 class="font-bold text-slate-900 text-sm uppercase tracking-wide">Cashier Drawer Blind Audits & Shift Reconciliations</h1>
            <p class="text-xs text-slate-500">Comprehensive drawer count logs for Day Shift (06:00 AM – 06:00 PM) and Night Shift (06:00 PM – 06:00 AM).</p>
        </div>
        <div class="text-xs font-mono text-slate-600">
            Total Shift Logs: <strong class="text-[#421A2B]">{{ $shifts->total() }}</strong>
        </div>
    </div>

    <!-- Shifts Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>SHIFT TURNOVER AUDIT LOG</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>SHIFT DATE</th>
                        <th>TYPE</th>
                        <th>OPENED BY</th>
                        <th>CLOSED BY</th>
                        <th>ROOM REVENUE</th>
                        <th>KITCHEN REVENUE</th>
                        <th>SYSTEM GROSS</th>
                        <th>COUNTED CASH</th>
                        <th>EXPECTED CASH</th>
                        <th>VARIANCE STATUS</th>
                        <th>HANDOFF / AUDIT NOTES</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($shifts as $sh)
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
                            <td class="font-mono font-bold text-slate-900">{{ $sh->closed_at ? '₱'.number_format($sh->cash_total, 2) : 'Pending count' }}</td>
                            <td class="font-mono">{{ $sh->closed_at ? '₱'.number_format($sh->expected_cash, 2) : 'Pending close' }}</td>
                            <td>
                                @if(!$sh->closed_at)
                                    <span class="text-slate-500">In progress</span>
                                @elseif($variance > 0)
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-900 font-bold text-[10px] font-mono rounded">
                                        +₱{{ number_format($variance, 2) }} (Over)
                                    </span>
                                @elseif($variance < 0)
                                    <span class="px-2 py-0.5 bg-rose-100 text-rose-900 font-bold text-[10px] font-mono rounded">
                                        -₱{{ number_format(abs($variance), 2) }} (Short)
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-900 font-bold text-[10px] font-mono rounded">
                                        ✓ Balanced
                                    </span>
                                @endif
                            </td>
                            <td class="text-left pl-3 text-[11px] text-slate-600 max-w-xs truncate">
                                {{ $sh->handoff_notes ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="py-8 text-center text-slate-400">No shift turnover logs recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($shifts->hasPages())
            <div class="p-2 border-t mt-2">
                {{ $shifts->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
