@extends('layouts.app')
@section('title', 'Report Exports')
@section('subtitle', 'Choose a report, select the dates, then review or download Excel')
@section('content')
@php
    $chosenReport = old('report', $report['type']);
    $chosenRange = old('range', $selectedRange);
    $chosenShift = old('shift', $period['shift']);
    $fromLabel = \Carbon\CarbonImmutable::parse($period['start_date'])->format('M j, Y');
    $toLabel = \Carbon\CarbonImmutable::parse($period['end_date'])->format('M j, Y');
    $shiftChoices = ['all' => ['Both shifts', '6:30 AM to next 6:30 AM'], 'day' => ['Day only', '6:30 AM–6:30 PM'], 'night' => ['Night only', '6:30 PM to next 6:30 AM']];
    $basisLabel = $period['calendar'] ? 'Calendar dates · matching P&L' : ($period['finance_day'] ? 'Daily Finance · 6:30 AM cutoff' : $shiftChoices[$period['shift']][0].' · 6:30 AM / PM');
    $sheetCount = count($report['sheets']);
@endphp
<div class="space-y-5 report-workspace">
    <form method="GET" action="{{ route('admin.accounting.reports') }}" id="report-filters" class="space-y-4 report-controls"
          data-applied-report="{{ $report['type'] }}" data-applied-range="{{ $selectedRange }}" data-applied-start="{{ $period['start_date'] }}" data-applied-end="{{ $period['end_date'] }}" data-applied-shift="{{ $period['shift'] }}">
        <input name="calendar" value="{{ (int) $period['calendar'] }}" type="hidden">
        <input name="finance_day" value="{{ (int) $period['finance_day'] }}" type="hidden">
        <fieldset class="hms-card space-y-3">
            <legend class="sr-only">Choose a report</legend>
            <div><h2 class="font-bold text-brand"><span class="report-step" aria-hidden="true">1</span>Choose a report</h2><p class="mt-1 text-sm text-slate-600">Pick the information you need. Each Excel file includes the sheets listed in its preview.</p></div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach($types as $key => $label)
                    <label class="report-choice">
                        <input type="radio" name="report" value="{{ $key }}" required @checked($chosenReport === $key) class="peer sr-only">
                        <span class="report-choice-body block h-full rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-[#ba9cac] peer-checked:border-brand peer-checked:bg-[#f8f0f4] peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand">
                            <span class="flex items-start justify-between gap-3"><strong class="block text-sm text-slate-900">{{ $label }}</strong><span class="report-choice-mark" aria-hidden="true"></span></span>
                            <span class="mt-2 block text-xs leading-relaxed text-slate-600">{{ $descriptions[$key] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('report')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
        </fieldset>
        <fieldset class="hms-card space-y-4">
            <legend class="sr-only">Choose dates and shift</legend>
            <h2 class="font-bold text-brand"><span class="report-step" aria-hidden="true">2</span>Choose dates and shift</h2>
            <div class="flex flex-wrap gap-2" role="group" aria-label="Date range shortcuts">
                @foreach($rangePresets as $key => $preset)
                    <label class="cursor-pointer"><input class="peer sr-only" type="radio" name="range" required value="{{ $key }}" data-start="{{ $preset['start'] }}" data-end="{{ $preset['end'] }}" @checked($chosenRange === $key)><span class="block rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand">{{ $preset['label'] }}</span></label>
                @endforeach
                <label class="cursor-pointer"><input class="peer sr-only" type="radio" name="range" required value="custom" @checked($chosenRange === 'custom')><span class="block rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand">Custom dates</span></label>
            </div>
            @error('range')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-4">
                <label class="text-sm font-semibold text-slate-700">From date<input name="start_date" type="date" required class="form-control-hms mt-1 block" value="{{ old('start_date', $period['start_date']) }}" aria-describedby="report-date-help">@error('start_date')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-semibold text-slate-700">Through date<input name="end_date" type="date" required class="form-control-hms mt-1 block" value="{{ old('end_date', $period['end_date']) }}" aria-describedby="report-date-help">@error('end_date')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
            </div>
            @if($period['calendar'] || $period['finance_day'])
                <input name="shift" value="all" type="hidden">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                    {{ $period['calendar'] ? 'These dates follow the Profit & Loss calendar range. Both shifts are included.' : 'This report follows Daily Finance accounting dates, with a 6:30 AM cutoff. Both shifts are included.' }}
                    <a id="report-hotel-shifts" class="mt-1 block font-semibold text-brand underline" href="{{ route('admin.accounting.reports', ['report' => $report['type'], 'range' => 'custom', 'start_date' => $period['start_date'], 'end_date' => $period['end_date'], 'shift' => 'all']) }}">Choose a hotel shift instead</a>
                </div>
            @else
                <div class="grid gap-2 sm:grid-cols-3" role="group" aria-label="Reporting shift">
                    @foreach($shiftChoices as $key => [$label, $hours])
                        <label class="cursor-pointer"><input class="peer sr-only" type="radio" name="shift" value="{{ $key }}" required @checked($chosenShift === $key)><span class="block rounded-lg border border-slate-200 p-3 peer-checked:border-brand peer-checked:bg-[#f8f0f4] peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand"><strong class="block text-sm text-slate-800">{{ $label }}</strong><span class="mt-1 block text-xs text-slate-600">{{ $hours }}</span></span></label>
                    @endforeach
                </div>
            @endif
            @error('shift')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
            <p id="report-date-help" class="text-xs leading-relaxed text-slate-500">@if($period['calendar'])Dates match the existing P&L calendar filters.@else A hotel day starts at 6:30 AM. Night shift continues into the next morning. For a date range, the selected shift is included on every date.@endif Choose up to 366 dates per file.</p>
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                <button class="inline-flex items-center justify-center rounded-lg bg-brand px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" name="format" value="preview">Show report</button>
                <button class="inline-flex items-center justify-center rounded-lg border border-[#c5aebb] bg-[#fbf6f9] px-5 py-2.5 text-sm font-bold text-brand hover:bg-[#f2e6ed] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" name="format" value="xlsx">Download Excel</button>
                <p class="text-xs text-slate-500">Both buttons use your selections above.</p>
            </div>
        </fieldset>
    </form>
    <p id="report-pending" class="report-controls rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status" aria-live="polite" hidden><strong>Preview not refreshed.</strong> Click Show report to update it. Download Excel will use your new selections.</p>
    <section class="hms-card space-y-3" id="report-preview">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500"><span class="report-step report-controls" aria-hidden="true">3</span>Report preview</p><h2 class="text-lg font-bold text-brand">{{ $report['title'] }}</h2><p class="mt-1 text-sm text-slate-600">{{ $report['description'] }}</p></div>
            <button id="report-print" class="report-controls shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50" type="button" onclick="window.print()" aria-describedby="report-pending">Print this preview</button>
        </div>
        <div class="flex flex-wrap gap-x-5 gap-y-2 rounded-lg bg-[#faf7f9] p-3 text-sm text-slate-700">
            <span><strong>Dates:</strong> {{ $fromLabel }}@if($period['start_date'] !== $period['end_date']) – {{ $toLabel }}@endif</span>
            <span>{{ $basisLabel }}</span>
            <span><strong>{{ $sheetCount }}</strong> Excel {{ $sheetCount === 1 ? 'sheet' : 'sheets' }}</span>
        </div>
        <p class="text-xs text-slate-500">Showing the selections used to load this preview. Generated {{ $report['generated_at'] }} · {{ $report['property'] }}</p>
        @if($sheetCount > 1)<p class="report-controls text-xs text-slate-600">Choose a sheet below to review it. Download Excel and Print include all {{ $sheetCount }} sheets.</p>@endif
        <nav class="report-controls flex flex-wrap gap-2" id="report-tabs" aria-label="Preview sheets">
            @foreach($report['sheets'] as $table)
                <a id="report-tab-{{ $loop->index }}" class="report-sheet-tab inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50" href="#report-sheet-{{ $loop->index }}"><span>{{ $table['name'] }}</span><span class="rounded bg-slate-100 px-1.5 py-0.5 font-normal text-slate-500">{{ count($table['rows']) }} {{ count($table['rows']) === 1 ? 'row' : 'rows' }}</span></a>
            @endforeach
        </nav>
    </section>
    @foreach($report['sheets'] as $table)
        <section class="hms-card report-sheet space-y-3" id="report-sheet-{{ $loop->index }}" aria-labelledby="report-tab-{{ $loop->index }}">
            <div class="report-print-heading"><strong>{{ $report['property'] }}</strong><p>{{ $report['title'] }} · {{ $fromLabel }} – {{ $toLabel }} · {{ $basisLabel }}</p></div>
            <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-bold text-brand">{{ $table['name'] }}</h3><span class="text-xs text-slate-500">{{ count($table['rows']) }} {{ count($table['rows']) === 1 ? 'row' : 'rows' }}</span></div>
            @if($table['rows'] === [])
                <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center"><p class="font-semibold text-slate-700">Nothing recorded for these dates and shift.</p><p class="mt-1 text-sm text-slate-500">Try a different date range or reporting shift.</p><a class="report-controls mt-3 inline-block text-sm font-semibold text-brand underline" href="#report-filters">Change dates</a></div>
            @else
                @if(count($table['headers']) > 4)<p class="report-controls text-xs text-slate-500">Scroll sideways to see all columns. Every column is included in Excel.</p>@endif
                <div class="overflow-x-auto report-table-wrap" tabindex="0" aria-label="{{ $table['name'] }} table; scroll to view more columns">
                    <table class="hms-table report-table {{ count($table['headers']) <= 4 ? 'report-table-summary' : '' }}">
                        <thead><tr>@foreach($table['headers'] as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
                        <tbody>@foreach($table['rows'] as $row)<tr>@foreach($row as $cell)<td>@if(is_int($cell) || is_float($cell)){{ number_format($cell, floor($cell) == $cell ? 0 : 2) }}@else{{ $cell ?? '—' }}@endif</td>@endforeach</tr>@endforeach</tbody>
                        @if($table['totals'] !== [])<tfoot><tr>@foreach($table['headers'] as $column => $header)<td class="font-bold">@if($column === 0)TOTAL @elseif(in_array($column, $table['totals'], true)){{ number_format(array_sum(array_column($table['rows'], $column)), 2) }}@endif</td>@endforeach</tr></tfoot>@endif
                    </table>
                </div>
            @endif
        </section>
    @endforeach
    <details class="hms-card text-xs text-slate-600"><summary class="cursor-pointer font-semibold text-slate-700">How to read this report</summary><div class="mt-3 space-y-2">@foreach($report['notes'] as $note)<p>{{ $note }}</p>@endforeach</div></details>
</div>
<style>
    .report-step { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; margin-right: .5rem; border-radius: 50%; background: #f2e6ed; color: #572039; font-size: .75rem; font-weight: 700; }
    .report-choice { display: block; cursor: pointer; }
    .report-choice-mark { flex: 0 0 auto; width: .9rem; height: .9rem; margin-top: .15rem; border: 1px solid #c3b5bf; border-radius: 50%; background: white; }
    .report-choice input:checked + .report-choice-body .report-choice-mark { border: 4px solid #572039; }
    .report-sheet-tab[aria-selected="true"] { background: #572039; color: white; border-color: #572039; }
    .report-sheet-tab[aria-selected="true"] > span:last-child { background: #74465b; color: white; }
    .report-table { min-width: 700px; }
    .report-table td { vertical-align: top; white-space: normal; min-width: 95px; max-width: 400px; overflow-wrap: anywhere; }
    .report-table-summary { min-width: 0; width: 100%; }
    .report-table-summary td { min-width: 0; }
    .report-table-wrap { max-height: 36rem; }
    .report-table thead th { position: sticky; top: 0; z-index: 1; }
    .report-print-heading { display: none; }
    @media print {
        @page { size: A3 landscape; margin: 10mm; }
        .report-controls, .app-topbar, .app-sidebar, .topbar-actions { display: none !important; }
        .app-workspace, .app-shell, main { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .report-sheet, .report-sheet[hidden] { display: block !important; box-shadow: none; border: 0; }
        .report-sheet + .report-sheet { break-before: page; }
        .report-print-heading { display: block; margin-bottom: 1rem; font-size: 10pt; }
        .report-table-wrap { overflow: visible; max-height: none; }
        .report-table { width: 100%; min-width: 0; table-layout: fixed; font-size: 8pt; }
        .report-table th, .report-table td { position: static; min-width: 0; max-width: none; padding: 4px; color: #000; }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        tr { break-inside: avoid; }
    }
</style>
<script>
(() => {
    const form = document.getElementById('report-filters');
    if (!form) return;
    const fields = ['report', 'range', 'start_date', 'end_date', 'shift'];
    const applied = JSON.stringify([form.dataset.appliedReport, form.dataset.appliedRange, form.dataset.appliedStart, form.dataset.appliedEnd, form.dataset.appliedShift]);
    const pending = document.getElementById('report-pending');
    const print = document.getElementById('report-print');
    const shiftLink = document.getElementById('report-hotel-shifts');
    const update = () => {
        const data = new FormData(form);
        const changed = JSON.stringify(fields.map(name => data.get(name))) !== applied;
        pending.hidden = !changed;
        print.disabled = changed;
        if (shiftLink) {
            const url = new URL(form.action, window.location.href);
            for (const name of fields) url.searchParams.set(name, data.get(name) || '');
            url.searchParams.set('shift', 'all');
            shiftLink.href = url.toString();
        }
    };
    form.addEventListener('change', event => {
        const field = event.target;
        if (field.name === 'range' && field.value !== 'custom') {
            form.elements.namedItem('start_date').value = field.dataset.start;
            form.elements.namedItem('end_date').value = field.dataset.end;
        }
        if (field.name === 'start_date' || field.name === 'end_date') form.querySelector('input[name="range"][value="custom"]').checked = true;
        update();
    });
    form.addEventListener('input', event => {
        if (event.target.name === 'start_date' || event.target.name === 'end_date') {
            form.querySelector('input[name="range"][value="custom"]').checked = true;
            update();
        }
    });
    update();
    const tabs = Array.from(document.querySelectorAll('.report-sheet-tab'));
    const panels = tabs.map(tab => document.getElementById(tab.hash.slice(1)));
    const tabList = document.getElementById('report-tabs');
    if (!tabs.length) return;
    tabList.setAttribute('role', 'tablist');
    const activate = (index, remember = false) => {
        tabs.forEach((tab, at) => {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panels[at].id);
            tab.setAttribute('aria-selected', String(at === index));
            tab.tabIndex = at === index ? 0 : -1;
            panels[at].setAttribute('role', 'tabpanel');
            panels[at].hidden = at !== index;
        });
        if (remember) window.history.replaceState(null, '', tabs[index].hash);
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', event => { event.preventDefault(); activate(index, true); });
        tab.addEventListener('keydown', event => {
            let next = index;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else if (event.key === ' ') { event.preventDefault(); activate(index, true); return; }
            else return;
            event.preventDefault();
            activate(next, true);
            tabs[next].focus();
        });
    });
    const initial = tabs.findIndex(tab => tab.hash === window.location.hash);
    activate(initial < 0 ? 0 : initial);
})();
</script>
@endsection
