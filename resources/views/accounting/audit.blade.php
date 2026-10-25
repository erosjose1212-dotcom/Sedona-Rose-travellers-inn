@extends('layouts.app')
@section('title', 'Audit History')
@section('subtitle', $propertyAccess ? 'Property audit events' : 'Your audit events')
@section('content')
<div class="hms-card">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4"><p class="text-sm text-slate-600">Events are recorded from this upgrade onward. Print requests do not confirm physical printing.</p><a href="{{ route('transactions.index') }}" class="btn-hms-primary">Transactions</a></div>
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
        <label class="text-sm">Search event / details<input name="q" maxlength="100" class="form-control-hms block" value="{{ $filters['q'] ?? '' }}"></label>
        <label class="text-sm">From<input name="from" type="date" class="form-control-hms block" value="{{ $filters['from'] ?? '' }}"></label>
        <label class="text-sm">To<input name="to" type="date" class="form-control-hms block" value="{{ $filters['to'] ?? '' }}"></label>
        @if(!empty($filters['room_id']))<input type="hidden" name="room_id" value="{{ $filters['room_id'] }}">@endif
        @if(!empty($filters['folio_id']))<input type="hidden" name="folio_id" value="{{ $filters['folio_id'] }}">@endif
        <button class="btn-hms-primary">Filter</button><a href="{{ route('transactions.audit') }}">Clear</a>
    </form>
    @if($propertyAccess)
    <section class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
        <h2 class="font-bold text-brand mb-2">Statement / Billing print summary by room and stay</h2>
        <p class="text-xs text-slate-600 mb-2">Print requests within the selected filters. Room is the stay's current room; each print's details retain its room at printing. Counts do not confirm paper was printed.</p>
        <div class="overflow-x-auto"><table class="w-full text-sm text-left">
            <thead><tr><th class="p-2">Room / stay</th><th class="p-2">Print requests</th><th class="p-2">First / latest request</th><th class="p-2">History</th></tr></thead>
            <tbody>@forelse($printSummaries as $summary)<tr class="border-t border-slate-200">
                <td class="p-2 font-semibold">Room {{ $summary->room_number }}<div class="text-xs">{{ $summary->stay_reference }}</div></td>
                <td class="p-2 font-bold">{{ $summary->print_count }}</td>
                <td class="p-2 text-xs">{{ \Carbon\Carbon::parse($summary->first_print_at, config('app.timezone'))->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }}<div>{{ \Carbon\Carbon::parse($summary->last_print_at, config('app.timezone'))->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }}</div></td>
                <td class="p-2"><a class="underline" href="{{ route('transactions.audit', array_merge($filters, ['folio_id' => $summary->folio_id])) }}">View prints and changes</a></td>
            </tr>@empty<tr><td colspan="4" class="p-3 text-slate-500">No Statement / Billing print requests in this period.</td></tr>@endforelse</tbody>
        </table></div><div class="mt-2">{{ $printSummaries->links() }}</div>
    </section>
    @endif
    <div class="overflow-x-auto"><table class="w-full text-sm text-left">
        <thead class="bg-slate-100"><tr><th class="p-3">When / actor</th><th class="p-3">Event / source</th><th class="p-3">Recorded details</th></tr></thead>
        <tbody>@forelse($events as $event)<tr class="border-b border-slate-200">
            <td class="p-3">{{ \Carbon\Carbon::parse($event->created_at, config('app.timezone'))->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }}<div>{{ $event->actor ?? 'Not recorded' }}</div></td>
            <td class="p-3 font-semibold">{{ $event->event === 'billing.print_requested' ? 'Statement / Billing print requested' : $event->event }}<div class="text-xs font-normal">{{ $event->stay_reference ? 'Room '.$event->room_number.' · '.$event->stay_reference : class_basename($event->auditable_type ?? '').' #'.$event->auditable_id }}</div></td>
            <td class="p-3">
                @php($recorded = json_decode($event->new_values ?? 'null', true))
                @if($event->event === 'billing.print_requested')
                    <p class="font-semibold">Copy #{{ $recorded['copy_number'] ?? '—' }} · Print requested</p>
                    @if(!empty($recorded['snapshot']))
                        @php($snapshot = $recorded['snapshot'])
                        <p class="text-xs">Room {{ $snapshot['room_number'] }} · {{ $snapshot['folio']['stay_mode'] === 'open' ? 'Open Time' : strtoupper($snapshot['folio']['rate_tier']) }} · Extra hours: {{ $snapshot['folio']['extra_hours'] }}</p>
                        <p class="text-xs">Check-in: {{ \Carbon\Carbon::parse($snapshot['billing']['checkInTime'])->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }} · Expected out: {{ \Carbon\Carbon::parse($snapshot['billing']['expectedCheckoutAt'])->setTimezone('Asia/Manila')->format('m/d/Y h:i A') }}</p>
                        <p class="text-xs">Room ₱{{ number_format($snapshot['billing']['roomRate'], 2) }} · Discount ₱{{ number_format($snapshot['billing']['totalDiscount'], 2) }} · Balance ₱{{ number_format($snapshot['billing']['finalBalanceToPay'], 2) }}</p>
                    @else<p class="text-xs text-slate-500">Older print request: bill snapshot was not recorded.</p>@endif
                @endif
                <details><summary class="cursor-pointer">View details</summary><pre class="text-xs whitespace-pre-wrap break-all">{{ json_encode(['before' => json_decode($event->old_values ?? 'null', true), 'recorded' => $recorded], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
            </td>
        </tr>@empty<tr><td colspan="3" class="p-6 text-center text-slate-500">No matching audit events.</td></tr>@endforelse</tbody>
    </table></div><div class="mt-4">{{ $events->links() }}</div>
</div>
@endsection
