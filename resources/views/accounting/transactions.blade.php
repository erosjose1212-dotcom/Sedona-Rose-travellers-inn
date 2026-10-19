@extends('layouts.app')
@section('title', 'Transaction History')
@section('subtitle', $propertyAccess ? 'Property money movements' : 'Your recorded money movements')
@section('content')
<div class="hms-card">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p class="text-sm text-slate-600">Collections include deposits and refunds. Earned revenue is reported separately.</p>
        <a class="btn-hms-primary" href="{{ route('transactions.audit') }}">Audit history</a>
    </div>
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
        <label class="text-sm">Search source / GCash reference<input class="form-control-hms block" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
        <label class="text-sm">From<input class="form-control-hms block" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label class="text-sm">To<input class="form-control-hms block" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <label class="text-sm">Kind<select name="kind" class="form-control-hms block"><option value="">All</option>@foreach(['deposit', 'deposit_refund', 'settlement', 'write_off', 'pos_sale', 'pos_refund', 'drawer_expense', 'cash_drop', 'float_addition', 'bank_deposit'] as $kind)<option value="{{ $kind }}" @selected(($filters['kind'] ?? '') === $kind)>{{ str_replace('_', ' ', ucfirst($kind)) }}</option>@endforeach</select></label>
        <label class="text-sm">Shift<input class="form-control-hms block w-20" type="number" min="1" name="shift_id" value="{{ $filters['shift_id'] ?? '' }}"></label>
        <button class="btn-hms-primary">Filter</button><a href="{{ route('transactions.index') }}">Clear</a>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-100"><tr><th class="p-3">Posted / source</th><th class="p-3">Kind / provenance</th><th class="p-3">Cashier / shift</th><th class="p-3 text-right">Cash</th><th class="p-3 text-right">GCash</th><th class="p-3">Provider / refund links</th></tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr class="border-b border-slate-200">
                    <td class="p-3">{{ $transaction->posted_at }}<div class="font-semibold">{{ $transaction->reference }}</div></td>
                    <td class="p-3">{{ str_replace('_', ' ', $transaction->kind) }}<div class="text-xs text-slate-500">{{ match($transaction->origin) { 'journal' => 'Journal entry #'.$transaction->id, 'custody' => 'Custody movement #'.$transaction->id, 'expense' => 'Paid expense voucher #'.$transaction->id, default => 'Legacy / existing '.$transaction->origin } }}</div></td>
                    <td class="p-3">{{ $transaction->actor ?? 'Not recorded' }}<div class="text-xs">Shift {{ $transaction->shift_id ?? 'not recorded' }}</div></td>
                    <td class="p-3 text-right font-mono">{{ number_format($transaction->cash_amount, 2) }}</td>
                    <td class="p-3 text-right font-mono">{{ number_format($transaction->gcash_amount, 2) }}</td>
                    <td class="p-3 text-xs">@if($transaction->gcash_amount != 0){{ $transaction->gcash_reference ?? 'Reference not recorded' }}<div class="text-amber-800">{{ $transaction->origin === 'journal' && in_array($transaction->id, $matchedPaymentIds) ? 'Matched to imported statement' : 'Recorded; provider verification pending' }}</div>@endif
                    @if($transaction->kind === 'deposit_refund' && $transaction->origin === 'journal')
                        @php($links = $refundLinks->get($transaction->id, collect()))
                        @foreach($links as $link)<div>Deposit entry #{{ $link->deposit_entry_id }}: {{ number_format($link->amount, 2) }}</div>@endforeach
                        @php($unallocated = abs((float) $transaction->cash_amount + (float) $transaction->gcash_amount) - (float) $links->sum('amount'))
                        @if($unallocated > 0.005)<div>Original deposit entry unavailable (legacy): {{ number_format($unallocated, 2) }}</div>@endif
                    @endif</td>
                </tr>
            @empty<tr><td colspan="6" class="p-6 text-center text-slate-500">No matching money movements.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $transactions->links() }}</div>
</div>
@endsection
