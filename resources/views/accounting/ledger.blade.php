@extends('layouts.app')

@section('title', 'General Transaction Journal & Cash Flow Stream')
@section('subtitle', 'Accounting & Financial Hub | Real-Time General Audit Journal & Cash Flow Ledger')

@section('top_action')
    <a href="{{ route('admin.accounting.pnl') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; View Profit & Loss Statement
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-7xl mx-auto">

    <!-- Header Summary -->
    <div class="bg-white p-3 border-l-4 border-[#421A2B] shadow-sm flex items-center justify-between">
        <div>
            <h1 class="font-bold text-slate-900 text-sm uppercase tracking-wide">Real-Time Financial Audit Ledger</h1>
            <p class="text-xs text-slate-500">Double-entry audit feed tracking guest folio settlements, walk-in dining receipts, and petty cash expense disbursements.</p>
        </div>
        <div class="text-xs font-mono text-slate-600">
            Audit Mode: <strong class="text-emerald-700">Active Live Stream</strong>
        </div>
    </div>

    <!-- Section 1: Guest Folios Audit Stream -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>1. GUEST FOLIOS & ROOM SETTLEMENT JOURNAL</span>
            <span class="text-xs text-slate-500 font-mono">Recent 50 Check-Ins</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>FOLIO #</th>
                        <th>DATE / TIME</th>
                        <th>ROOM</th>
                        <th>GUEST NAME</th>
                        <th>TIER</th>
                        <th>GROSS TOTAL</th>
                        <th>DISCOUNT</th>
                        <th>NET COLLECTED</th>
                        <th>PAYMENT</th>
                        <th>PRINTABLES</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($folios as $f)
                        <tr>
                            <td class="font-mono font-bold text-[#421A2B]">{{ $f->transaction_id }}</td>
                            <td class="font-mono text-[11px]">{{ $f->checked_in_at->format('m/d/Y h:i A') }}</td>
                            <td class="font-bold">Room {{ $f->room->number ?? 'N/A' }}</td>
                            <td class="text-left pl-3 font-medium">{{ $f->guest->name ?? 'Guest' }}</td>
                            <td class="font-mono font-bold uppercase text-[10px]">{{ $f->rate_tier }}</td>
                            <td class="font-mono">₱{{ number_format($f->gross_total, 2) }}</td>
                            <td class="font-mono text-rose-700">-₱{{ number_format($f->discount_amount, 2) }}</td>
                            <td class="font-mono font-bold text-emerald-800">₱{{ number_format($f->net_total, 2) }}</td>
                            <td>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase {{ $f->payment_method === 'gcash' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $f->payment_method ?? 'CASH' }}
                                </span>
                            </td>
                            <td class="space-x-1 whitespace-nowrap text-right">
                                @if($f->status !== 'voided')
                                    <a href="{{ route('folios.receipt', $f->id) }}" target="_blank" class="px-2 py-0.5 bg-[#421A2B] text-white font-bold text-[10px] rounded hover:bg-[#341421]">Receipt</a>
                                @endif
                                <a href="{{ route('folios.deposit_slip', $f->id) }}" target="_blank" class="px-2 py-0.5 bg-slate-700 text-white font-bold text-[10px] rounded hover:bg-slate-800">Deposit</a>
                                <a href="{{ route('folios.billing', $f->id) }}" target="_blank" class="px-2 py-0.5 bg-blue-700 text-white font-bold text-[10px] rounded hover:bg-blue-800">Billing</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-6 text-center text-slate-400">No folio transactions recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 2: Walk-In POS Orders & Cash Expenses Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">

        <!-- Walk-in POS Orders -->
        <div class="lg:col-span-6">
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
                    <span>2. DIRECT WALK-IN POS SALES</span>
                </div>
                <div class="overflow-x-auto max-h-80 overflow-y-auto">
                    <table class="hms-table">
                        <thead>
                            <tr>
                                <th>ORDER #</th>
                                <th>TIME</th>
                                <th>ITEMS</th>
                                <th>PAYMENT</th>
                                <th>TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($walkinOrders as $wo)
                                <tr>
                                    <td class="font-mono font-bold">{{ $wo->transaction_id }}</td>
                                    <td class="font-mono text-[11px]">{{ $wo->created_at->format('h:i A') }}</td>
                                    <td class="text-left pl-2 text-[11px]">
                                        @foreach($wo->items as $it)
                                            <span class="inline-block mr-1">{{ $it->quantity }}x {{ $it->item_name }}</span>
                                        @endforeach
                                    </td>
                                    <td class="font-mono uppercase text-[10px]">{{ $wo->payment_method ?? 'CASH' }}</td>
                                    <td class="font-mono font-bold text-emerald-800">₱{{ number_format($wo->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-center text-slate-400">No walk-in POS sales today.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Operating Expense Disbursements -->
        <div class="lg:col-span-6">
            <div class="hms-card">
                <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
                    <span>3. PETTY CASH & EXPENSE DISBURSEMENTS</span>
                </div>
                <div class="overflow-x-auto max-h-80 overflow-y-auto">
                    <table class="hms-table">
                        <thead>
                            <tr>
                                <th>VOUCHER #</th>
                                <th>DATE</th>
                                <th>CATEGORY</th>
                                <th>DESCRIPTION</th>
                                <th>AMOUNT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($expenses as $ex)
                                <tr>
                                    <td class="font-mono font-bold text-rose-800">{{ $ex->voucher_number }}</td>
                                    <td class="font-mono text-[11px]">{{ $ex->expense_date->format('m/d/Y') }}</td>
                                    <td class="font-bold uppercase text-[10px]">{{ ucwords(str_replace('_', ' ', $ex->category)) }}</td>
                                    <td class="text-left pl-2 text-[11px] truncate max-w-xs">{{ $ex->description }}</td>
                                    <td class="font-mono font-bold text-rose-700">₱{{ number_format($ex->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-center text-slate-400">No expense vouchers recorded.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>
@endsection
