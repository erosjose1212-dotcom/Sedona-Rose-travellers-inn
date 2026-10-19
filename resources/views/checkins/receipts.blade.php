@extends('layouts.app')
@section('title', 'Daily SCTI Register')
@section('subtitle', 'Receipts in checkout order')
@section('content')
<div class="space-y-4">
    <div class="hms-card flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-800">{{ $day->format('F j, Y') }}</h2>
            <p class="text-sm font-semibold text-slate-700">{{ $periodLabel }} · Manila time</p>
            <p class="text-sm text-slate-500">{{ $start->format('m/d/Y h:i A') }} to {{ $end->format('m/d/Y h:i A') }}</p>
            <p class="text-sm text-slate-500">First receipt: <strong class="font-mono text-slate-800">{{ $firstReceipt ?? '—' }}</strong><br>Last receipt: <strong class="font-mono text-slate-800">{{ $lastReceipt ?? '—' }}</strong></p>
        </div>
        <form method="GET" action="{{ route('receipts.index') }}" class="flex flex-wrap items-end gap-2">
            <label class="text-sm font-semibold text-slate-700">Shift start date
                <input class="form-control-hms block" type="date" name="date" value="{{ $date }}" required>
            </label>
            <label class="text-sm font-semibold text-slate-700 max-w-full">Shift
                <select class="form-control-hms block max-w-full" name="shift">
                    <option value="all" @selected($shift === 'all')>Both shifts (24 hours)</option>
                    <option value="day" @selected($shift === 'day')>Day · 6:30 AM–6:30 PM</option>
                    <option value="night" @selected($shift === 'night')>Night · 6:30 PM–next 6:30 AM</option>
                </select>
            </label>
            <button class="btn-occupied" type="submit">Show period</button>
            <a class="btn-addon" href="{{ route('receipts.index') }}">Current day</a>
            <a class="btn-addon" href="{{ route('checkins.index', ['date' => $date]) }}">Check-in register</a>
        </form>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div class="hms-card bg-emerald-50"><p class="text-sm text-emerald-800">Receipts issued</p><p class="text-2xl font-bold text-emerald-900">{{ $receiptCount }}</p></div>
        <div class="hms-card bg-blue-50"><p class="text-sm text-blue-800">Guests checked out</p><p class="text-2xl font-bold text-blue-900">{{ $guestCount }}</p></div>
    </div>
    <div class="hms-card">
        <p class="mb-3 text-sm text-slate-600">SCTI numbers are assigned when checkout completes. Reprints keep the original number. Arrival counts are in the Check-in register.</p>
        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead><tr><th>Receipt number</th><th>Stay reference</th><th>Room</th><th>Guest</th><th>People</th><th>Issued at</th><th>Cashier</th><th></th></tr></thead>
                <tbody>
                    @forelse($folios as $folio)
                        <tr>
                            <td class="font-mono whitespace-nowrap font-bold">{{ $folio->receipt_number }}</td>
                            <td class="font-mono whitespace-nowrap">{{ $folio->transaction_id }}</td>
                            <td class="whitespace-nowrap font-semibold">Room {{ $folio->room->number }}</td>
                            <td>{{ $folio->guest?->name ?? 'Guest' }}</td>
                            <td>{{ $folio->guest?->headcount ?? 0 }}</td>
                            <td class="whitespace-nowrap">{{ $folio->receipt_issued_at->copy()->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }}</td>
                            <td>{{ $folio->checkedOutBy?->name ?? 'Staff' }}</td>
                            <td><a class="btn-addon" href="{{ route('folios.receipt', $folio) }}" target="_blank" rel="noopener">Receipt</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-6 text-center text-slate-500">No receipts issued for this shift period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $folios->links() }}</div>
        <p class="mt-3 text-xs text-slate-500">The end time belongs to the next shift. SCTI numbering still resets at midnight; a night shift can include two receipt dates. Receipt numbers are preserved.</p>
    </div>
</div>
@endsection
