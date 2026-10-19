@extends('layouts.app')
@section('title', 'Login History')
@section('subtitle', 'Staff sign-ins and sign-outs')
@section('content')
<div class="hms-card">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Account access history</h2>
            <p class="text-sm text-slate-600 mt-1">Exact sign-in and sign-out times are shown in {{ config('app.timezone') }}.</p>
        </div>
        <span class="rounded-md bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ number_format($events->total()) }} matching events</span>
    </div>
    <p class="text-sm text-slate-600 mb-5">History starts when this update is installed. A sign-in does not mean the person is still online. Closing a browser, losing connection, or letting a session expire does not record an exact sign-out time. Failed attempts do not prove the account owner made them.</p>
    <form method="GET" action="{{ route('admin.login-history.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <label class="text-sm grow">Name, email or IP address
            <input name="q" maxlength="100" class="form-control-hms block w-full" value="{{ $filters['q'] ?? '' }}" placeholder="Find an account or computer">
        </label>
        <label class="text-sm">Event
            <select name="event" class="form-control-hms block">
                <option value="">All events</option>
                <option value="auth.login" @selected(($filters['event'] ?? '') === 'auth.login')>Signed in</option>
                <option value="auth.logout" @selected(($filters['event'] ?? '') === 'auth.logout')>Signed out</option>
                <option value="auth.failed" @selected(($filters['event'] ?? '') === 'auth.failed')>Failed sign-in</option>
            </select>
        </label>
        <label class="text-sm">From<input name="from" type="date" class="form-control-hms block" value="{{ $filters['from'] ?? '' }}"></label>
        <label class="text-sm">To<input name="to" type="date" class="form-control-hms block" value="{{ $filters['to'] ?? '' }}"></label>
        <button class="btn-occupied !px-4 !py-2 !text-sm !whitespace-normal">Filter</button>
        <a href="{{ route('admin.login-history.index') }}" class="text-sm font-semibold text-slate-600 px-2 py-2">Clear</a>
    </form>
    <table class="block md:table w-full text-sm text-left table-fixed">
        <thead class="hidden md:table-header-group bg-slate-100"><tr>
            <th class="p-3 w-44">Date and time</th>
            <th class="p-3">Account at the time</th>
            <th class="p-3 w-36">Event</th>
            <th class="p-3 w-44">Connection</th>
        </tr></thead>
        <tbody class="block md:table-row-group">
            @forelse($events as $event)
                <tr class="block md:table-row border-b border-slate-200">
                    <td class="block md:table-cell p-3 align-top">
                        <span class="block md:hidden text-xs text-slate-500 mb-1">Date and time</span>
                        <time datetime="{{ \Carbon\Carbon::parse($event->created_at)->toIso8601String() }}" class="font-mono text-xs">
                            {{ \Carbon\Carbon::parse($event->created_at)->format('M j, Y') }}<span class="block">{{ \Carbon\Carbon::parse($event->created_at)->format('h:i:s A') }}</span>
                        </time>
                    </td>
                    <td class="block md:table-cell p-3 align-top break-all">
                        <span class="block md:hidden text-xs text-slate-500 mb-1">Account</span>
                        <div class="font-semibold">{{ $event->details['name'] ?? 'Account not identified' }}</div>
                        <div class="text-slate-600">{{ $event->details['email'] ?? 'Email not recorded' }}</div>
                        <div class="text-xs text-slate-500 mt-1">{{ implode(', ', $event->details['roles'] ?? []) ?: 'No role recorded' }}</div>
                    </td>
                    <td class="block md:table-cell p-3 align-top">
                        @if($event->event === 'auth.login')
                            <span class="inline-block rounded-md px-2 py-1 text-xs font-semibold bg-emerald-50 text-emerald-800">Signed in</span>
                        @elseif($event->event === 'auth.logout')
                            <span class="inline-block rounded-md px-2 py-1 text-xs font-semibold bg-slate-100 text-slate-700">Signed out</span>
                        @else
                            <span class="inline-block rounded-md px-2 py-1 text-xs font-semibold bg-rose-50 text-rose-800">Failed sign-in</span>
                        @endif
                    </td>
                    <td class="block md:table-cell p-3 align-top break-all">
                        <span class="block md:hidden text-xs text-slate-500 mb-1">Connection</span>
                        <div class="font-mono text-xs">{{ $event->ip_address ?? 'IP not recorded' }}</div>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-slate-600">Browser details</summary>
                            <p class="text-xs text-slate-600 mt-2">{{ $event->user_agent ?: 'Browser not recorded' }}</p>
                        </details>
                    </td>
                </tr>
            @empty
                <tr class="block md:table-row"><td colspan="4" class="block md:table-cell p-6 text-center text-slate-500">No matching sign-ins or sign-outs.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $events->links() }}</div>
</div>
@endsection
