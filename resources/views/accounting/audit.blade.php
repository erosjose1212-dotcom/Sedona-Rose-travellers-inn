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
        <button class="btn-hms-primary">Filter</button><a href="{{ route('transactions.audit') }}">Clear</a>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm text-left">
        <thead class="bg-slate-100"><tr><th class="p-3">When / actor</th><th class="p-3">Event / source</th><th class="p-3">Recorded details</th></tr></thead>
        <tbody>@forelse($events as $event)<tr class="border-b border-slate-200">
            <td class="p-3">{{ $event->created_at }}<div>{{ $event->actor ?? 'Not recorded' }}</div></td>
            <td class="p-3 font-semibold">{{ $event->event }}<div class="text-xs font-normal">{{ class_basename($event->auditable_type ?? '') }} #{{ $event->auditable_id }}</div></td>
            <td class="p-3"><details><summary class="cursor-pointer">View details</summary><pre class="text-xs whitespace-pre-wrap break-all">{{ json_encode(['before' => json_decode($event->old_values ?? 'null', true), 'recorded' => json_decode($event->new_values ?? 'null', true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></td>
        </tr>@empty<tr><td colspan="3" class="p-6 text-center text-slate-500">No matching audit events.</td></tr>@endforelse</tbody>
    </table></div><div class="mt-4">{{ $events->links() }}</div>
</div>
@endsection
