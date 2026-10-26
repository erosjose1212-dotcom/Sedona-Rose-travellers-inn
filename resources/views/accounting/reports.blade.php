@extends('layouts.app')
@section('title', 'Report Exports')
@section('subtitle', 'Accounting | Shift summaries, occupied rooms, profit & loss and sales')
@section('top_action')
<div class="flex flex-wrap gap-2">
    <a class="inline-flex items-center justify-center rounded bg-brand px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" href="{{ route('admin.accounting.reports', ['report' => $report['type'], 'range' => 'custom', 'calendar' => (int) $period['calendar'], 'finance_day' => (int) $period['finance_day'], 'start_date' => $period['start_date'], 'end_date' => $period['end_date'], 'shift' => $period['shift'], 'format' => 'xlsx']) }}">Download Excel</a>
    <button class="inline-flex items-center justify-center rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" type="button" onclick="window.print()">Print preview</button>
</div>
@endsection
@section('content')
<div class="space-y-4">
    <section class="hms-card report-controls space-y-3">
        <div class="flex flex-wrap gap-2">
            @foreach(['today' => 'Today', 'this_week' => 'This Week', 'this_month' => 'This Month', 'last_month' => 'Last Month'] as $key => $label)
                <a class="inline-flex items-center justify-center rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" href="{{ route('admin.accounting.reports', ['report' => $report['type'], 'range' => $key, 'calendar' => (int) $period['calendar'], 'finance_day' => (int) $period['finance_day'], 'shift' => $period['shift']]) }}">{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" action="{{ route('admin.accounting.reports') }}" class="flex flex-wrap items-end gap-3">
            <input name="range" value="custom" type="hidden">
            <input name="calendar" value="{{ (int) $period['calendar'] }}" type="hidden">
            <input name="finance_day" value="{{ (int) $period['finance_day'] }}" type="hidden">
            <label class="text-sm font-semibold">Report<select name="report" class="form-control-hms block mt-1">@foreach($types as $key => $label)<option value="{{ $key }}" @selected($key === $report['type'])>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">From {{ $period['calendar'] ? 'date' : 'business date' }}<input name="start_date" type="date" required class="form-control-hms block mt-1" value="{{ $period['start_date'] }}"></label>
            <label class="text-sm font-semibold">To {{ $period['calendar'] ? 'date' : 'business date' }}<input name="end_date" type="date" required class="form-control-hms block mt-1" value="{{ $period['end_date'] }}"></label>
            @if($period['calendar'] || $period['finance_day'])
            <input name="shift" value="all" type="hidden"><span class="text-sm">{{ $period['calendar'] ? 'Calendar dates · matching P&L' : 'Daily Finance · 6:30 AM cutoff' }}</span>
            @else
            <label class="text-sm font-semibold">Shifts<select name="shift" class="form-control-hms block mt-1"><option value="all" @selected($period['shift'] === 'all')>Both shifts</option><option value="day" @selected($period['shift'] === 'day')>Day · 6:30 AM–6:30 PM</option><option value="night" @selected($period['shift'] === 'night')>Night · 6:30 PM–6:30 AM</option></select></label>
            @endif
            <button class="inline-flex items-center justify-center rounded bg-brand px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" name="format" value="preview">Preview report</button>
            <button class="inline-flex items-center justify-center rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" name="format" value="xlsx">Download Excel</button>
        </form>
        <p class="text-xs text-slate-600">Choose up to 366 dates. @if($period['calendar'])Calendar dates match the existing P&L date filters and expense dates.@elseif($period['finance_day'])Accounting dates run 6:30 AM–next 6:30 AM, matching Daily Finance.@else Day or Night selects that shift on every date in the range. Both covers 6:30 AM on the first date through 6:30 AM after the last date.@endif</p>
    </section>
    <section class="hms-card space-y-2">
        <h1 class="text-lg font-bold text-brand">{{ $report['property'] }} — {{ $report['title'] }}</h1>
        <p class="text-sm">{{ $period['start_date'] }} to {{ $period['end_date'] }} · {{ $period['calendar'] ? 'Calendar dates matching P&L' : ($period['finance_day'] ? 'Daily Finance · 6:30 AM cutoff' : ucfirst($period['shift']).' shifts') }} · Asia/Manila</p>
        <p class="text-sm text-slate-600">{{ $report['description'] }}</p>
        <p class="text-xs text-slate-500">Generated {{ $report['generated_at'] }}. Excel includes all matching rows.</p>
        <nav class="report-controls flex flex-wrap gap-3 text-sm" aria-label="Report sections">@foreach($report['sheets'] as $table)<a class="underline text-brand" href="#report-sheet-{{ $loop->index }}">{{ $table['name'] }} ({{ count($table['rows']) }})</a>@endforeach</nav>
    </section>
    @foreach($report['sheets'] as $table)
        <section class="hms-card report-sheet space-y-3" id="report-sheet-{{ $loop->index }}">
            <h2 class="font-bold text-brand">{{ $table['name'] }}</h2>
            <div class="overflow-x-auto">
                <table class="hms-table report-table">
                    <thead><tr>@foreach($table['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse($table['rows'] as $row)
                            <tr>@foreach($row as $cell)<td>@if(is_int($cell) || is_float($cell)){{ number_format($cell, floor($cell) == $cell ? 0 : 2) }}@else{{ $cell ?? '—' }}@endif</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($table['headers']) }}">No recorded entries in this selection.</td></tr>
                        @endforelse
                    </tbody>
                    @if($table['totals'] !== [])
                        <tfoot><tr>@foreach($table['headers'] as $column => $header)<td class="font-bold">@if($column === 0)TOTAL @elseif(in_array($column, $table['totals'], true)){{ number_format(array_sum(array_column($table['rows'], $column)), 2) }}@endif</td>@endforeach</tr></tfoot>
                    @endif
                </table>
            </div>
        </section>
    @endforeach
    <section class="hms-card space-y-2 text-xs text-slate-600">@foreach($report['notes'] as $note)<p>{{ $note }}</p>@endforeach</section>
</div>
<style>
    .report-table { min-width: 700px; }
    .report-table td { vertical-align: top; white-space: normal; min-width: 95px; max-width: 400px; overflow-wrap: anywhere; }
    @media print {
        @page { size: A3 landscape; margin: 10mm; }
        .report-controls, .app-topbar, .app-sidebar, .topbar-actions { display: none !important; }
        .app-workspace, .app-shell, main { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .report-sheet { break-before: page; box-shadow: none; border: 0; }
        .report-sheet .overflow-x-auto { overflow: visible; }
        .report-table { width: 100%; min-width: 0; table-layout: fixed; font-size: 8pt; }
        .report-table th, .report-table td { min-width: 0; max-width: none; padding: 4px; color: #000; }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        tr { break-inside: avoid; }
    }
</style>
@endsection
