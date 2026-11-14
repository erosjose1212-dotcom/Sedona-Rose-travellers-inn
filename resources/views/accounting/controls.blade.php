@extends('layouts.app')
@section('title', 'Daily Finance & Reconciliation')
@section('subtitle', 'Review collections, match GCash / bank records, then close the business day')
@section('top_action')
    <a href="{{ route('admin.accounting.reports', ['report' => 'financial_audit', 'range' => 'today', 'report_date' => $date, 'finance_day' => 1]) }}" class="inline-flex items-center justify-center rounded bg-brand px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Daily Excel reports</a>
@endsection
@section('content')
@php
    $closed = $day?->status === 'closed';
    $periodStart = \Carbon\Carbon::parse($snapshot['starts_at'])->setTimezone('Asia/Manila');
    $periodEnd = \Carbon\Carbon::parse($snapshot['ends_at'])->setTimezone('Asia/Manila');
    $kindLabels = ['deposit' => 'Guest deposit received', 'deposit_refund' => 'Guest deposit refunded', 'settlement' => 'Guest checkout payment', 'write_off' => 'Approved loss / write-off', 'pos_sale' => 'POS sale', 'pos_refund' => 'POS refund', 'bank_deposit' => 'Cash deposited to bank', 'cash_drop' => 'Cash removed from drawer', 'float_addition' => 'Cash added to drawer'];
@endphp
<div class="space-y-4 max-w-7xl mx-auto">
    <section class="hms-card space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <label class="text-sm font-semibold text-slate-700">Business date
                    <input name="date" value="{{ $date }}" type="date" required class="form-control-hms block mt-1">
                </label>
                <button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Review day</button>
            </form>
            <span class="rounded-full px-3 py-1 text-xs font-bold {{ $closed ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-800' }}">{{ $closed ? 'Closed · saved totals' : 'Open · live totals' }} · Version {{ $day?->version ?? 0 }}</span>
        </div>
        <p class="text-sm font-semibold text-slate-700">{{ $periodStart->format('M d, Y h:i A') }} → {{ $periodEnd->format('M d, Y h:i A') }} (hotel time)</p>
        <p class="text-xs text-slate-500">This accounting day ends at the {{ $periodEnd->format('g:i A') }} cutoff, Manila time. Transactions at the ending time belong to the next business day.</p>
        <nav aria-label="Finance sections" class="flex flex-wrap gap-2 text-xs font-semibold">
            <a href="#collections" class="rounded border border-slate-200 bg-slate-50 px-3 py-2">1. Review money</a>
            <a href="#matching" class="rounded border border-slate-200 bg-slate-50 px-3 py-2">2. Match GCash / bank</a>
            <a href="#close-day" class="rounded border border-slate-200 bg-slate-50 px-3 py-2">3. Close day</a>
            <a href="#exports" class="rounded border border-slate-200 bg-slate-50 px-3 py-2">4. Export for accountant</a>
        </nav>
    </section>

    <section id="collections" class="space-y-3 scroll-mt-4" aria-labelledby="collections-heading">
        <h2 id="collections-heading" class="font-bold text-slate-800">1. Review the money recorded</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="hms-card !bg-emerald-50 !border-emerald-200">
                <p class="text-xs font-bold text-emerald-900">Net cash collected</p>
                <p class="mt-1 text-2xl font-bold font-mono text-emerald-900">₱{{ number_format($snapshot['cash_cents'] / 100, 2) }}</p>
                <p class="mt-2 text-xs text-emerald-800">Recorded cash payments less refunds.</p>
            </div>
            <div class="hms-card !bg-blue-50 !border-blue-200">
                <p class="text-xs font-bold text-blue-900">Net GCash collected</p>
                <p class="mt-1 text-2xl font-bold font-mono text-blue-900">₱{{ number_format($snapshot['gcash_cents'] / 100, 2) }}</p>
                <p class="mt-2 text-xs text-blue-800">Recorded GCash payments less refunds, before fees.</p>
            </div>
            @foreach(['opening_deposit_cents' => 'Guest deposits held at start', 'closing_deposit_cents' => 'Guest deposits still held at end'] as $key => $label)
                <div class="hms-card">
                    <p class="text-xs font-bold text-slate-600">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold font-mono text-slate-800">₱{{ number_format($snapshot[$key] / 100, 2) }}</p>
                    <p class="mt-2 text-xs text-slate-500">Journal-backed deposits owed to guests or available for their bills.</p>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-slate-600">Collections include deposits. They are not earned revenue or the expected drawer balance. Drawer expenses, cash transfers and opening float are separate. <a class="underline text-blue-700" href="{{ route('shifts.index') }}">View Shifts & Drawer</a>.</p>
        @if(($snapshot['opening_legacy_deposit_cents'] ?? 0) || ($snapshot['closing_legacy_deposit_cents'] ?? 0))
            <p class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Older deposits without journal records: opening ₱{{ number_format(($snapshot['opening_legacy_deposit_cents'] ?? 0) / 100, 2) }} · closing ₱{{ number_format(($snapshot['closing_legacy_deposit_cents'] ?? 0) / 100, 2) }}. These are separate from the deposit cards and need reconciliation.</p>
        @endif
        <details class="hms-card">
            <summary class="cursor-pointer font-semibold text-sm">View {{ count($snapshot['payments']) }} payments and {{ count($snapshot['movements']) }} cash transfers for this day</summary>
            <div class="overflow-x-auto mt-3">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50"><tr><th class="p-2">Source</th><th class="p-2">Transaction / reference</th><th class="p-2 text-right">Cash</th><th class="p-2 text-right">GCash</th></tr></thead>
                    <tbody>
                        @foreach($snapshot['payments'] as $entry)
                            <tr class="border-b border-slate-100"><td class="p-2">Payment #{{ $entry['id'] }}<p class="text-xs text-slate-500">{{ $entry['folio_id'] ? 'Stay #'.$entry['folio_id'] : 'Order #'.$entry['order_id'] }}</p></td><td class="p-2">{{ $kindLabels[$entry['kind']] ?? ucwords(str_replace('_', ' ', $entry['kind'])) }}<p class="text-xs text-slate-500">{{ $entry['gcash_reference'] }}</p></td><td class="p-2 text-right font-mono">₱{{ number_format($entry['cash_amount'], 2) }}</td><td class="p-2 text-right font-mono">₱{{ number_format($entry['gcash_amount'], 2) }}</td></tr>
                        @endforeach
                        @foreach($snapshot['movements'] as $movement)
                            <tr class="border-b border-slate-100"><td class="p-2">Cash transfer #{{ $movement['id'] }}</td><td class="p-2">{{ $kindLabels[$movement['kind']] ?? ucwords(str_replace('_', ' ', $movement['kind'])) }}<p class="text-xs text-slate-500">{{ $movement['reference'] }}</p></td><td class="p-2 text-right font-mono">₱{{ number_format($movement['amount'], 2) }}</td><td class="p-2 text-right">—</td></tr>
                        @endforeach
                        @if(empty($snapshot['payments']) && empty($snapshot['movements']))<tr><td colspan="4" class="p-4 text-slate-500">No money movements recorded for this day.</td></tr>@endif
                    </tbody>
                </table>
            </div>
        </details>
        <details class="hms-card">
            <summary class="cursor-pointer font-semibold text-sm">Current active guest balances · {{ $balances->count() }} stays</summary>
            <p class="mt-2 text-xs text-slate-500">Live balances now, even when reviewing an older business date. These stays do not need to check out before day closing.</p>
            <div class="grid gap-3 mt-3 sm:grid-cols-2">
                @forelse($balances as $balance)
                    <a href="{{ route('checkout.process', $balance['id']) }}" class="rounded border border-slate-200 bg-slate-50 p-3 text-sm"><p class="font-semibold">Room {{ $balance['room'] ?? '—' }} · {{ $balance['guest'] }}</p><p class="mt-1 text-xs text-slate-600">Deposit received ₱{{ number_format($balance['deposit'], 2) }} · Amount due ₱{{ number_format($balance['balance'], 2) }}</p></a>
                @empty<p class="text-sm text-slate-500">No active guest stays.</p>@endforelse
            </div>
        </details>
    </section>

    <section id="matching" class="hms-card space-y-4 scroll-mt-4" aria-labelledby="matching-heading">
        <div><h2 id="matching-heading" class="font-bold text-slate-800">2. Match GCash and bank records</h2><p class="mt-1 text-sm text-slate-600">Compare recorded payments with your downloaded merchant or bank statement. Importing does not add a sale or payment.</p></div>
        @if(!$closed)
            <div class="rounded border border-blue-100 bg-blue-50 p-3 space-y-3">
                <div class="flex flex-wrap justify-between gap-2"><h3 class="text-sm font-semibold text-blue-900">Import a statement CSV</h3><a class="text-xs font-semibold underline text-blue-800" href="{{ route('admin.finance.template') }}">Download CSV template</a></div>
                <p class="text-xs text-slate-600">Use the template columns: reference, posted_at, gross_amount, fee_amount, net_amount. Net = gross − fees. Times use hotel time unless an offset is supplied.</p>
                <form method="POST" enctype="multipart/form-data" action="{{ route('admin.finance.import') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="text-xs font-semibold">Statement type<select name="provider" class="form-control-hms block mt-1"><option value="gcash">GCash merchant</option><option value="bank">Bank deposits</option></select></label>
                    <label class="text-xs font-semibold min-w-0">CSV file<input name="statement" type="file" accept=".csv,text/csv" required class="block mt-2 max-w-full"></label>
                    <button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Import statement</button>
                </form>
                <p class="text-xs text-slate-500">Each row belongs to the business date of its timestamp. Duplicate rows are skipped.</p>
            </div>
        @else<p class="text-sm text-slate-500">This day is closed. The owner must reopen it before corrections or additional matching.</p>@endif
        <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold text-sm">Statement rows for {{ $date }}</h3><a href="{{ route('transactions.index') }}" class="text-xs text-blue-700 underline">Open payment journal</a></div>
        <p class="text-xs text-slate-500">Select payments with the same reference and direction. Their total must equal the statement gross, before fees. A different reviewer must match payments they did not record.</p>
        @forelse($statements as $statement)
            @php
                $excluded = in_array($statement->id, $excludedStatementIds);
                $matched = in_array($statement->id, $matchedStatementIds);
                $candidates = ($statement->provider === 'gcash' ? $paymentCandidates : $bankCandidates)->get($statement->reference, collect())
                    ->filter(fn ($candidate) => ((float) ($statement->provider === 'gcash' ? $candidate->gcash_amount : -$candidate->amount) > 0) === ((float) $statement->gross_amount > 0));
            @endphp
            <article class="rounded border border-slate-200 p-3 space-y-3">
                <div class="flex flex-wrap justify-between gap-2">
                    <div><h4 class="font-semibold text-sm">{{ $statement->provider === 'gcash' ? 'GCash' : 'Bank' }} · {{ $statement->reference }}</h4><p class="mt-1 text-xs text-slate-500">Statement #{{ $statement->id }} · {{ $statement->posted_at->copy()->setTimezone('Asia/Manila')->format('M d, Y h:i A') }}</p></div>
                    <span class="text-xs font-semibold {{ $excluded ? 'text-slate-500' : ($matched ? 'text-emerald-700' : 'text-amber-800') }}">{{ $excluded ? 'Excluded after review' : ($matched ? 'Matched' : 'Needs matching') }}</span>
                </div>
                <div class="flex flex-wrap gap-4 text-xs text-slate-600"><span>Gross <strong class="text-slate-900">₱{{ number_format($statement->gross_amount, 2) }}</strong></span><span>Fees <strong>₱{{ number_format($statement->fee_amount, 2) }}</strong></span><span>Net received <strong>₱{{ number_format($statement->net_amount, 2) }}</strong></span></div>
                @if(!$matched && !$excluded && !$closed)
                    @if($candidates->isNotEmpty())
                        <form method="POST" action="{{ route('admin.finance.match', $statement) }}" class="space-y-3">
                            @csrf
                            <input name="operation_key" type="hidden" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <fieldset class="space-y-2"><legend class="text-xs font-semibold mb-2">Select the matching recorded transactions</legend>
                                @foreach($candidates as $candidate)
                                    <label class="flex items-start gap-3 rounded border border-slate-200 bg-slate-50 p-3 text-sm cursor-pointer">
                                        <input type="checkbox" name="entries[]" value="{{ $candidate->id }}" class="mt-1">
                                        <span class="min-w-0 flex-1"><span class="font-semibold">{{ $statement->provider === 'gcash' ? 'Payment' : 'Bank transfer' }} #{{ $candidate->id }} · {{ $kindLabels[$candidate->kind] ?? ucwords(str_replace('_', ' ', $candidate->kind)) }}</span>
                                            @if($statement->provider === 'gcash')<span class="block text-xs text-slate-500">{{ $candidate->folio ? 'Room '.$candidate->folio->room?->number.' · '.$candidate->folio->transaction_id : ($candidate->order?->transaction_id ?? 'No stay / order reference') }} · {{ $candidate->user?->name ?? 'Recorded operator' }}</span>@endif
                                            <span class="block text-xs text-slate-500">{{ $candidate->posted_at->copy()->setTimezone('Asia/Manila')->format('M d, Y h:i A') }}</span>
                                        </span>
                                        <strong class="font-mono shrink-0">₱{{ number_format($statement->provider === 'gcash' ? $candidate->gcash_amount : -$candidate->amount, 2) }}</strong>
                                    </label>
                                @endforeach
                            </fieldset>
                            <button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Match selected transactions</button>
                        </form>
                    @else<p class="rounded bg-amber-50 p-3 text-xs text-amber-900">No eligible unmatched transactions with this reference and direction. Check the payment journal, or ask another reviewer if you recorded the payment yourself.</p>@endif
                @endif
            </article>
        @empty<p class="rounded bg-slate-50 p-4 text-sm text-slate-500">No statement rows imported for this business date. Import your GCash or bank CSV to begin matching.</p>@endforelse
        {{ $statements->links() }}
    </section>

    <section id="close-day" class="hms-card space-y-3 scroll-mt-4" aria-labelledby="close-heading">
        <h2 id="close-heading" class="font-bold text-slate-800">3. Review checks and close the day</h2>
        @if($closed)
            <p class="rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">Day closed. Version {{ $day->version }} is saved. Ongoing guest stays remain open.</p>
            @can('manage-accounting-setup')
                <details><summary class="cursor-pointer text-sm text-rose-700">Reopen this day for a correction</summary>
                    <form method="POST" action="{{ route('admin.finance.reopen', $day) }}" class="flex flex-wrap items-end gap-3 mt-3">@csrf<label class="text-sm">Reason for reopening<input name="reason" required maxlength="1000" class="form-control-hms block mt-1"></label><button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Reopen day</button></form>
                    <p class="mt-2 text-xs text-slate-500">Previous versions remain in history.</p>
                </details>
            @endcan
        @else
            @if(!$cutoffPassed)<p class="rounded bg-amber-50 p-3 text-sm text-amber-900">This accounting day is still in progress. Closing becomes available after {{ $periodEnd->format('M d, Y h:i A') }}.</p>@endif
            @if($snapshot['exceptions'])
                <p class="text-sm font-semibold text-amber-900">{{ count($snapshot['exceptions']) }} checks need attention before closing:</p>
                <ul class="list-disc pl-5 space-y-2 text-sm text-rose-800">@foreach($snapshot['exceptions'] as $exception)<li>{{ $exception }}</li>@endforeach</ul>
            @else<p class="rounded bg-emerald-50 p-3 text-sm text-emerald-800">All reconciliation checks passed.</p>@endif
            <div class="flex flex-wrap gap-4 text-sm"><a href="{{ route('approvals.index') }}" class="text-blue-700 underline">Review approvals</a><a href="{{ route('admin.accounting.expenses') }}" class="text-blue-700 underline">Review paid expenses</a><a href="{{ route('shifts.index') }}" class="text-blue-700 underline">Review drawer</a></div>
            <form method="POST" action="{{ route('admin.finance.close') }}" class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3">
                @csrf<input name="business_date" type="hidden" value="{{ $date }}">
                <label class="text-sm">Closing note / review reference<input name="reason" required maxlength="1000" class="form-control-hms block mt-1"></label>
                <button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand disabled:opacity-50 disabled:cursor-not-allowed" @disabled(!$cutoffPassed || count($snapshot['exceptions']) > 0)>Close business day</button>
            </form>
            <p class="text-xs text-slate-500">Closing saves a fixed copy of this day's totals and prevents further postings to it. Active guests can stay checked in.</p>
        @endif
    </section>

    <section id="exports" class="hms-card space-y-3 scroll-mt-4" aria-labelledby="exports-heading">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 id="exports-heading" class="font-bold text-slate-800">4. Export for the accountant</h2>
            @if($closed)<form method="POST" action="{{ route('admin.finance.export', $day) }}">@csrf<button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Prepare CSV for this day</button></form>@endif
        </div>
        <p class="text-sm text-slate-600">Close the day → prepare CSV → a different admin or owner approves → download → record manual delivery. The system does not send files to accounting software.</p>
        <h3 class="text-xs font-semibold text-slate-500">Recent export batches across all business dates</h3>
        <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="bg-slate-50"><tr><th class="p-3">Batch / status</th><th class="p-3">Contents</th><th class="p-3">Next action</th></tr></thead><tbody>
            @forelse($batches as $batch)
                <tr class="border-b border-slate-100"><td class="p-3">Batch #{{ $batch->id }} · day #{{ $batch->business_day_id }} · version {{ $batch->version }}<p class="text-xs text-slate-500">{{ $batch->destination }} · {{ ucfirst($batch->status) }}@if($batch->correction_of_id) · corrects #{{ $batch->correction_of_id }}@endif</p></td>
                    <td class="p-3"><details><summary class="cursor-pointer text-xs">Review {{ count($batch->lines) }} accounting lines</summary><p class="mt-2 text-xs break-all">SHA-256 {{ $batch->sha256 }}</p><pre class="text-xs whitespace-pre-wrap">{{ json_encode($batch->lines, JSON_PRETTY_PRINT) }}</pre></details></td>
                    <td class="p-3">@if($batch->status === 'draft' && $batch->created_by !== auth()->id())<form method="POST" action="{{ route('admin.finance.exports.review', $batch) }}">@csrf<button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Approve export</button></form>
                        @elseif(in_array($batch->status, ['reviewed', 'delivered']))<a href="{{ route('admin.finance.exports.download', $batch) }}" class="text-blue-700 underline">Download reviewed CSV</a>@if($batch->status === 'reviewed')<form method="POST" action="{{ route('admin.finance.exports.delivered', $batch) }}" class="grid gap-2 mt-3">@csrf<label class="text-xs">Delivery / import reference<input name="reference" required maxlength="255" class="form-control-hms block mt-1"></label><button class="rounded bg-brand px-4 py-2 text-xs font-bold text-white hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Record manual delivery</button></form>@endif
                        @else<p class="text-xs text-amber-800">Waiting for a different admin or owner to review.</p>@endif</td>
                </tr>
            @empty<tr><td colspan="3" class="p-3 text-slate-500">No prepared exports.</td></tr>@endforelse
        </tbody></table></div>
        {{ $batches->links() }}
    </section>

    <details class="hms-card"><summary class="cursor-pointer font-semibold text-sm">Saved day versions for {{ $date }} · {{ $versions->count() }}</summary>
        <div class="mt-3">@forelse($versions as $version)<details class="mb-3"><summary class="cursor-pointer text-sm">Version {{ $version->version }} · {{ $version->created_at }} · reviewer #{{ $version->user_id }} · {{ $version->reason }}</summary><p class="text-xs break-all mt-2">SHA-256 {{ $version->sha256 }}</p><pre class="text-xs whitespace-pre-wrap break-all mt-2">{{ json_encode(json_decode($version->snapshot, true), JSON_PRETTY_PRINT) }}</pre></details>@empty<p class="text-sm text-slate-500">No saved closed versions for this day.</p>@endforelse</div>
    </details>
    <details class="hms-card" @if(request()->has('page')) open @endif><summary class="cursor-pointer font-semibold text-sm">Business-day history</summary><div class="mt-3">@forelse($days as $record)<p class="text-sm mb-2"><a href="{{ route('admin.finance.index', ['date' => $record->business_date->toDateString()]) }}" class="text-blue-700 underline">{{ $record->business_date->toDateString() }}</a> · {{ ucfirst($record->status) }} · version {{ $record->version }} · closed by #{{ $record->closed_by ?? '—' }}</p>@empty<p class="text-sm text-slate-500 mb-2">No business-day history yet.</p>@endforelse{{ $days->links() }}</div></details>
    @can('manage-accounting-setup')<p class="text-xs text-slate-500"><a href="{{ route('admin.finance.setup') }}" class="underline text-blue-700">Accounting setup</a> · Owner configuration for invoices and accountant exports.</p>@endcan
</div>
@endsection
