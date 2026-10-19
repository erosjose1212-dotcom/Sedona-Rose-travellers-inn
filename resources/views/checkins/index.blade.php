@extends('layouts.app')
@section('title', 'Daily Check-in Register')
@section('subtitle', 'Track the day’s stays and guests in one list')
@section('content')
<div class="space-y-4">
    <div class="hms-card flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-800">{{ $day->format('F j, Y') }}</h2>
            <p class="text-sm text-slate-500">Arrival register: midnight to midnight, Manila time.</p>
            <p class="text-sm text-slate-500">Last daily number: <strong class="font-mono text-slate-800">{{ $lastNumber ? str_pad((string) $lastNumber, 3, '0', STR_PAD_LEFT) : '—' }}</strong></p>
        </div>
        <form method="GET" action="{{ route('checkins.index') }}" class="flex flex-wrap items-end gap-2">
            <label class="text-sm font-semibold text-slate-700">Check-in record date
                <input class="form-control-hms block" type="date" name="date" value="{{ $date }}" required>
            </label>
            <button class="btn-occupied" type="submit">Show day</button>
            <a class="btn-addon" href="{{ route('checkins.index') }}">Today</a>
            <a class="btn-addon" href="{{ route('receipts.index', ['date' => $date]) }}">Checkout receipts</a>
        </form>
    </div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="hms-card bg-slate-50"><p class="text-sm text-slate-600">Registered stays</p><p class="text-2xl font-bold text-slate-800">{{ $counts['registered'] }}</p></div>
        <div class="hms-card bg-emerald-50"><p class="text-sm text-emerald-800">Valid stays</p><p class="text-2xl font-bold text-emerald-900">{{ $counts['stays'] }}</p></div>
        <div class="hms-card bg-blue-50"><p class="text-sm text-blue-800">Guests</p><p class="text-2xl font-bold text-blue-900">{{ $counts['guests'] }}</p></div>
        <div class="hms-card bg-rose-50"><p class="text-sm text-rose-800">Cancelled stays</p><p class="text-2xl font-bold text-rose-900">{{ $counts['cancelled'] }}</p></div>
    </div>
    <div class="hms-card">
        <p class="mb-3 text-sm text-slate-600">These are stay references. SCTI receipt numbers are issued at checkout. Cancelled stays are excluded from valid stay and guest totals.</p>
        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead><tr><th>No.</th><th>Stay reference</th><th>Room</th><th>Guest</th><th>People</th><th>Check-in time</th><th>Status</th><th>Recorded by</th></tr></thead>
                <tbody>
                    @forelse($folios as $folio)
                        <tr>
                            <td class="font-mono font-bold">{{ $folio->dailySequenceNumber() !== null ? str_pad((string) $folio->dailySequenceNumber(), 3, '0', STR_PAD_LEFT) : '—' }}</td>
                            <td class="font-mono whitespace-nowrap">{{ $folio->transaction_id ?? 'Unnumbered' }}</td>
                            <td class="whitespace-nowrap font-semibold">Room {{ $folio->room->number }}</td>
                            <td>{{ $folio->guest?->name ?? 'Guest' }}</td>
                            <td>{{ $folio->guest?->headcount ?? 0 }}</td>
                            <td class="whitespace-nowrap">{{ $folio->checked_in_at->copy()->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }}</td>
                            <td><span class="inline-block rounded px-2 py-1 text-xs font-semibold {{ $folio->status === 'voided' ? 'bg-rose-50 text-rose-800' : 'bg-slate-100 text-slate-700' }}">{{ match ($folio->status) { 'voided' => 'Cancelled', 'checked_out' => 'Checked out', default => 'In house' } }}</span></td>
                            <td>{{ $folio->cashier?->name ?? 'Staff' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-6 text-center text-slate-500">No stays recorded for this date.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $folios->links() }}</div>
        <p class="mt-3 text-xs text-slate-500">Older stay references stay unchanged. Correcting a check-in time keeps the stay under its original registration date.</p>
    </div>
</div>
@endsection
