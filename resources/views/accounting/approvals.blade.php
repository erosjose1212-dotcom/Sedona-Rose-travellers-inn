@extends('layouts.app')
@section('title', 'Approvals & Tasks')
@section('subtitle', 'Review requests, apply approved changes, and track outcomes')
@section('top_action')
    <a href="#newApprovalRequest" onclick="document.getElementById('newApprovalRequest').open = true" class="btn-occupied">+ New request</a>
@endsection
@section('content')
<div class="space-y-5">
    @if($errors->any())
        <div role="alert" class="hms-card border border-rose-300 bg-rose-50 text-rose-800">
            <p class="font-bold">The request could not be saved.</p>
            <ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @include('accounting.approval-queue')
    <details id="newApprovalRequest" class="hms-card" @if($selectedFolio) open @endif>
        <summary class="cursor-pointer font-bold">Create a new request</summary>
        <p class="text-sm text-slate-600 my-3">Choose the change you need. Fixed Senior, PWD and DC discounts can be applied directly by the cashier. Refunds do not return food or stock automatically.</p>
        @if(auth()->user()->hasRole('owner'))<p class="text-sm text-slate-600 mb-3">Your room-rate and custom-discount requests are automatically approved. Use Ready to apply to update the guest's bill.</p>@endif
    <div class="grid gap-4 lg:grid-cols-2">
    @foreach(['folio_override' => 'Room rate / custom guest discount', 'invoice_tax_treatment' => 'Statutory invoice tax basis', 'deposit_refund' => 'Return remaining guest deposit', 'pos_refund' => 'Refund a walk-in sale', 'statement_void' => 'Exclude an incorrect statement import', 'cash_drop' => 'Drop drawer cash to safe', 'float_addition' => 'Add drawer float', 'bank_deposit' => 'Deposit drawer cash to bank'] as $kind => $label)
        @continue($kind === 'statement_void' && !auth()->user()->can('review-financial'))
        @continue(in_array($kind, ['cash_drop', 'float_addition', 'bank_deposit']) && !$shift)
        <details class="hms-card" @if($kind === 'folio_override' && $selectedFolio) open @endif><summary class="cursor-pointer font-semibold">{{ $label }}</summary>
            <form method="POST" action="{{ route('approvals.store') }}" class="mt-4 grid gap-3">
                @csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="kind" value="{{ $kind }}">
                @if(in_array($kind, ['folio_override', 'deposit_refund', 'invoice_tax_treatment']))
                    <label>Guest folio<select name="source_id" required class="form-control-hms">@foreach($folios as $folio)<option value="{{ $folio->id }}" @selected($kind === 'folio_override' && $selectedFolio?->id === $folio->id)>Room {{ $folio->room?->number }} — #{{ $folio->id }} — {{ $folio->guest?->name }} — {{ $folio->status }}</option>@endforeach</select></label>
                @elseif($kind === 'pos_refund')
                    <label>Original sale payment<select name="source_id" required class="form-control-hms">@foreach($payments as $payment)<option value="{{ $payment->id }}">Payment #{{ $payment->id }} — order #{{ $payment->order_id }} — ₱{{ number_format($payment->cash_amount + $payment->gcash_amount, 2) }}</option>@endforeach</select></label>
                @elseif($kind === 'statement_void')
                    <label>Unmatched statement import<select name="source_id" required class="form-control-hms">@foreach($statements as $statement)<option value="{{ $statement->id }}">#{{ $statement->id }} {{ $statement->provider }} {{ $statement->reference }} {{ $statement->gross_amount }}</option>@endforeach</select></label>
                @else
                    <input type="hidden" name="source_id" value="{{ $shift->id }}"><p class="text-sm">Drawer #{{ $shift->id }}</p>
                @endif
                @if($kind === 'folio_override')
                    <p class="text-sm text-slate-500">Leave unchanged fields empty. Choosing a discount type with no custom amount restores that guest's recorded default. Senior and PWD discounts require an ID reference.</p>
                    <label>New room charge<input name="payload[room_charge]" type="number" step="0.01" min="0" class="form-control-hms"></label>
                    <p class="text-sm text-slate-500">Check-in time corrections are handled by RA (admin).</p>
                    <label>Guest discount<select name="payload[discount_type]" class="form-control-hms"><option value="">Unchanged</option>@foreach(['DC' => 'Sedona Card / DC', 'SENIOR' => 'Senior Citizen', 'PWD' => 'PWD', 'NONE' => 'Remove discount'] as $type => $discountLabel)<option value="{{ $type }}" @selected($selectedDiscountType === $type)>{{ $discountLabel }}</option>@endforeach</select></label>
                    <label>Discount ID reference<input name="payload[discount_id_ref]" maxlength="100" class="form-control-hms"></label>
                    @if(auth()->user()->hasAnyRole(['admin', 'owner']))
                        <label>Custom discount amount (₱, optional)<input name="payload[discount_override_amount]" type="number" step="0.01" min="0" max="500000" class="form-control-hms"></label>
                    @endif
                @elseif($kind === 'statement_void')
                    <p class="text-sm text-slate-500">The original import is retained. Matching or excluding an already matched row is blocked.</p>
                @elseif($kind === 'invoice_tax_treatment')
                    <label>Accountant-approved taxable amount, inclusive of VAT<input name="payload[taxable_amount]" type="number" step="0.01" min="0" required class="form-control-hms"></label><p class="text-sm text-slate-500">The remainder of the settled sale is exempt. Record the accountant / exemption evidence reference below.</p>
                @else
                    <label>Amount<input name="payload[amount]" type="number" step="0.01" min="0.01" required class="form-control-hms"></label>
                    @if(in_array($kind, ['deposit_refund', 'pos_refund']))
                        <label>Refund method<select name="payload[payment_method]" class="form-control-hms"><option value="cash">Cash</option><option value="gcash">GCash</option></select></label>
                        <label>GCash refund reference<input name="payload[gcash_reference]" maxlength="50" class="form-control-hms"></label>
                    @else
                        <label>Destination / source<select name="payload[destination]" class="form-control-hms">@if($kind === 'bank_deposit')<option value="bank">Bank</option>@else<option value="safe">Safe</option>@if($kind === 'float_addition')<option value="owner_funds">Owner funds</option>@endif @endif</select></label>
                        <label>Movement reference<input name="payload[reference]" required maxlength="100" class="form-control-hms"></label>
                    @endif
                @endif
                <label>Reason<textarea name="reason" required maxlength="1000" class="form-control-hms"></textarea></label>
                <label>Evidence reference<input name="evidence_reference" maxlength="255" class="form-control-hms"></label>
                <button class="btn-occupied">Submit request</button>
            </form>
        </details>
    @endforeach
    </div>
    </details>
    @can('record_payments')
    <details id="configuredInvoices" class="hms-card" @if(request()->has('invoice_folio_page') || request()->has('invoice_sale_page')) open @endif>
        <summary class="cursor-pointer font-semibold">Configured invoices</summary>
        <p class="text-sm text-slate-500 my-3">Issuance remains disabled until the owner enters approved legal, tax and numbering details.</p>
        <h3 class="font-semibold text-sm mb-3">Guest stays ({{ $invoiceFolios->total() }})</h3>
        @forelse($invoiceFolios as $folio)
            <form method="POST" action="{{ route('invoices.folio', $folio) }}" class="flex flex-wrap gap-3 items-center mb-2">
                @csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <span class="text-sm">Room {{ $folio->room?->number }} · {{ $folio->transaction_id }} · {{ $folio->guest?->name }}</span>
                <button class="btn-occupied">Issue / open invoice</button>
            </form>
        @empty<p class="text-sm text-slate-500">No settled guest stays.</p>@endforelse
        <div class="my-4">{{ $invoiceFolios->fragment('configuredInvoices')->links() }}</div>
        <h3 class="font-semibold text-sm mb-3">Walk-in sales ({{ $invoiceSales->total() }})</h3>
        @forelse($invoiceSales as $payment)
            <form method="POST" action="{{ route('invoices.order', $payment->order_id) }}" class="flex flex-wrap gap-3 items-center mb-2">
                @csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <span class="text-sm">Walk-in order {{ $payment->order?->transaction_id ?? '#'.$payment->order_id }}</span>
                <button class="btn-occupied">Issue / open invoice</button>
            </form>
        @empty<p class="text-sm text-slate-500">No paid walk-in sales.</p>@endforelse
        <div class="mt-4">{{ $invoiceSales->fragment('configuredInvoices')->links() }}</div>
    </details>@endcan
</div>
@endsection
