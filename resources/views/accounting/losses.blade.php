@extends('layouts.app')

@section('title', 'Approved Loss Slips & Bad Debt Ledger')
@section('subtitle', 'Accounting & Financial Hub | Force Checkout Loss Slips (FCE-######) & Bad Debt Ledger')

@section('top_action')
    <a href="{{ route('admin.accounting.pnl') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; Return to P&L Statement
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-7xl mx-auto">

    <!-- KPI Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
        <div class="bg-white p-3 border-l-4 border-rose-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] block">Cumulative Written-Off Bad Debt</span>
            <div class="text-xl font-mono font-black text-rose-700 mt-0.5">₱{{ number_format($totalWrittenOff, 2) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Unsettled room and dining balances written off</div>
        </div>

        <div class="bg-white p-3 border-l-4 border-slate-700 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] block">Approved Loss Slips</span>
            <div class="text-xl font-mono font-bold text-slate-900 mt-0.5">{{ $lossFolios->total() }} Slips</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Approved with FCE-###### slip codes</div>
        </div>

        <div class="bg-white p-3 border-l-4 border-amber-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] block">Review Status</span>
            <div class="text-xl font-mono font-bold text-amber-800 mt-0.5">Approval recorded</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Independent audit review is not yet tracked</div>
        </div>
    </div>

    <!-- Loss Slips Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>APPROVED FORCE CHECKOUT LOSS SLIPS (FCE-######)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>LOSS SLIP #</th>
                        <th>FOLIO #</th>
                        <th>DATE WRITTEN OFF</th>
                        <th>ROOM</th>
                        <th>GUEST NAME</th>
                        <th>REQUESTED BY</th>
                        <th>APPROVED BY</th>
                        <th>INCIDENT REASON</th>
                        <th>WRITTEN OFF (₱)</th>
                        <th>PRINTABLE</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lossFolios as $lf)
                        <tr>
                            <td class="font-mono font-bold text-rose-700">{{ $lf->loss_slip_reference ?? 'FCE-'.str_pad($lf->id, 6, '0', STR_PAD_LEFT) }}</td>
                            <td class="font-mono font-bold">{{ $lf->transaction_id }}</td>
                            <td class="font-mono text-[11px]">{{ ($lf->checked_out_at ?? $lf->updated_at)->format('m/d/Y h:i A') }}</td>
                            <td class="font-bold">Room {{ $lf->room->number ?? 'N/A' }}</td>
                            <td class="text-left pl-3 font-medium">{{ $lf->guest->name ?? 'Guest' }}</td>
                            <td>Not recorded</td>
                            <td class="font-bold text-slate-800">{{ $lf->forcedBy->name ?? 'Unavailable' }}</td>
                            <td class="text-left pl-3 text-[11px] text-slate-700 italic max-w-xs truncate">{{ $lf->force_reason ?? 'Skip-out' }}</td>
                            <td class="font-mono font-bold text-rose-700 text-right pr-3">₱{{ number_format($lf->net_total, 2) }}</td>
                            <td>
                                <a href="{{ route('folios.loss_slip', $lf->id) }}" target="_blank" class="px-2 py-0.5 bg-slate-800 text-white font-bold text-[10px] rounded hover:bg-black">
                                    Print Slip
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-8 text-center text-slate-400">No force checkout loss records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($lossFolios->hasPages())
            <div class="p-2 border-t mt-2">
                {{ $lossFolios->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
